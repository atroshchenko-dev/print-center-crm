<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\RisoPriceTier;
use App\Models\Service;

/**
 * RisoPricingService
 *
 * Calculates pricing for risograph duplication services.
 * Uses tiered pricing based on A3 sheet quantity.
 *
 * Formula:
 *   sheets_a3    = originals × ((format == A4) ? ceil(copies_per_original / 2) : copies_per_original)
 *   tier         = lookup(sheets_a3) from riso_price_tiers
 *   print_cost   = sheets_a3 × tier.cost_per_copy × sides
 *   paper_cost   = sheets_a3 × InventoryItem('Папір А3 80 г/м²').avg_cost
 *   total_cost   = print_cost + paper_cost
 *
 * Paper cost is dynamically taken from inventory AVCO (avg_cost).
 * Fallback: config('riso.paper_cost_a3') if inventory item not found.
 */
class RisoPricingService
{
    /**
     * Calculate riso pricing for given parameters.
     *
     * @param  int    $copies  Total copies across every original (originals × copies of each)
     * @param  string $format  'A3' or 'A4'
     * @param  int    $sides   1 or 2
     * @param  float|null $paperCostOverride  Override paper cost (for snapshot builder)
     * @param  int    $originals  How many distinct originals the run is made of
     * @return array{
     *   sheets_a3: int,
     *   cost_per_copy: float,
     *   print_cost: float,
     *   paper_cost: float,
     *   total_cost: float,
     *   tier_label: string,
     * }
     * @throws \RuntimeException if no tier found
     */
    public function calculate(int $copies, string $format = 'A3', int $sides = 1, ?float $paperCostOverride = null, int $originals = 1): array
    {
        $sheetsA3 = $this->copiesToSheets($copies, $format, $originals);

        $tier = RisoPriceTier::findForQuantity($sheetsA3);

        if (! $tier) {
            throw new \RuntimeException("Не знайдено тариф для {$sheetsA3} аркушів А3.");
        }

        $costPerCopy = (float) $tier->cost_per_copy;
        $paperCostA3 = $paperCostOverride ?? $this->getPaperCostA3();

        $printCost = $sheetsA3 * $costPerCopy * $sides;
        $paperCost = $sheetsA3 * $paperCostA3;
        $totalCost = $printCost + $paperCost;

        return [
            'sheets_a3'     => $sheetsA3,
            'cost_per_copy' => round($costPerCopy, 4),
            'print_cost'    => round($printCost, 2),
            'paper_cost'    => round($paperCost, 2),
            'total_cost'    => round($totalCost, 2),
            'tier_label'    => $tier->max_qty
                ? "{$tier->min_qty}–{$tier->max_qty}"
                : "{$tier->min_qty}+",
        ];
    }

    /**
     * Build JSONB snapshot for order_items.service_snapshot.
     */
    public function buildSnapshot(
        Service $service,
        int $copies,
        string $format,
        int $sides,
        ?int $paperId = null,
        int $originals = 1,
    ): array {
        // Resolve selected paper from inventory
        $paper = $paperId ? InventoryItem::find($paperId) : $this->getPaperA3Item();
        $paperCostPerSheet = $paper && (float) $paper->avg_cost > 0
            ? (float) $paper->avg_cost
            : (float) config('riso.paper_cost_a3', 0.80);

        $originals = max(1, $originals);
        $pricing = $this->calculate($copies, $format, $sides, $paperCostPerSheet, $originals);
        $markup  = (float) \App\Models\Setting::getValue(
            'riso_commercial_markup',
            config('riso.commercial_markup', 2.0),
        );

        $totalCost       = $pricing['total_cost'];
        $totalCommercial = round($totalCost * $markup, 2);

        return [
            'service_id'             => $service->id,
            'service_name'           => $service->name,
            'service_type'           => 'riso',
            'quantity'               => $copies,
            'riso_params'            => [
                'format'        => $format,
                'sides'         => $sides,
                'originals'     => $originals,
                'sheets_a3'     => $pricing['sheets_a3'],
                'cost_per_copy' => $pricing['cost_per_copy'],
                'tier_label'    => $pricing['tier_label'],
                'paper_cost_a3' => $paperCostPerSheet,
                'paper_name'    => $paper?->name ?? 'Невідомо',
                'markup'        => $markup,
            ],
            'unit_price_commercial'  => $copies > 0
                ? round($totalCommercial / $copies, 4)
                : 0,
            'unit_price_cost'        => $copies > 0
                ? round($totalCost / $copies, 4)
                : 0,
            'total_price_commercial' => $totalCommercial,
            'total_price_cost'       => $totalCost,
            'hardware_counters'      => [
                'bw_clicks'    => 0,
                'color_clicks' => 0,
                'riso_clicks'  => $pricing['sheets_a3'] * $sides,
            ],
            // Per-copy paper deduction (multiplied by $orderItem->quantity in autoDeductForOrder).
            // Derived from the sheet count rather than restated as a constant: for
            // A4 the halving happens per original, so 3 originals × 25 copies is
            // 39 sheets, not 38, and a flat 0.5 would deduct the 38.
            'inventory_deductions'   => $paper ? [[
                'inventory_item_id' => $paper->id,
                'qty'               => $copies > 0 ? $pricing['sheets_a3'] / $copies : 0,
            ]] : [],
        ];
    }

    /**
     * Convert copies → A3 sheets based on format.
     *
     * A risograph burns one master per original, so two different originals can
     * never share the two halves of an A3 sheet: the A4 halving is a per-original
     * rounding, not a rounding of the whole run. Rounding the total instead — the
     * shape this method had — undercounts one sheet for every original printed in
     * an odd number of copies, and that shortfall can drop the run below a tier
     * boundary: 2 originals × 99 copies A4 is 100 sheets at 0.23 ₴, but was
     * counted as 99 sheets and priced at 0.41 ₴, so the operator saw 23 ₴ on the
     * calculator and the order stored 40.59 ₴. The screen has always summed
     * per original (`RisoCalculator.vue`); this is the side that was wrong.
     */
    private function copiesToSheets(int $copies, string $format, int $originals = 1): int
    {
        $originals   = max(1, $originals);
        $perOriginal = (int) ceil($copies / $originals);

        $sheetsPerOriginal = match ($this->normalizeFormat($format)) {
            'A4'    => (int) ceil($perOriginal / 2),
            default => $perOriginal,
        };

        return $sheetsPerOriginal * $originals;
    }

    /**
     * Normalize format string: replace Cyrillic А with Latin A.
     */
    private function normalizeFormat(string $format): string
    {
        return strtoupper(str_replace(['а', 'А'], 'A', $format));
    }

    /**
     * Get paper A3 cost from inventory AVCO (dynamic).
     * Falls back to config('riso.paper_cost_a3') if item not found.
     */
    private function getPaperCostA3(): float
    {
        $item = $this->getPaperA3Item();

        return $item && (float) $item->avg_cost > 0
            ? (float) $item->avg_cost
            : (float) config('riso.paper_cost_a3', 0.80);
    }

    /**
     * Resolve default paper A3 inventory item (cached per request).
     */
    private function getPaperA3Item(): ?InventoryItem
    {
        return once(fn () => InventoryItem::where('name', config('riso.default_paper_a3_name'))
            ->where('is_active', true)
            ->first()
        );
    }
}
