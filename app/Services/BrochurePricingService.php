<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\Service;

/**
 * BrochurePricingService
 *
 * Calculates pricing for brochure services.
 * A brochure consists of:
 *   - Cover: 1 sheet per brochure (paper + print mode)
 *   - Block: N sheets per brochure with potentially mixed print modes
 *   - Binding: staple (скоба)
 *
 * Cost formula per brochure:
 *   cover_cost  = cover_paper_avg_cost + cover_click_cost
 *   block_cost  = Σ(block_paper_avg_cost × sheets + block_click_cost × clicks) per entry
 *   unit_cost   = cover_cost + block_cost
 *   total_cost  = unit_cost × тираж (print run)
 */
class BrochurePricingService
{
    /**
     * Build JSONB snapshot for order_items.service_snapshot.
     *
     * @param  Service  $service  The brochure service
     * @param  array  $params  Brochure parameters from frontend:
     *                         - format: 'А4' | 'А5'
     *                         - cover_paper_id: int (inventory item id)
     *                         - cover_mode: '1+0' | '1+1' | '4+0' | '4+4'
     *                         - block_paper_id: int (inventory item id)
     *                         - block_entries: [{mode: string, sheets: int}, ...]
     * @param  int  $quantity  Print run (тираж — кількість брошур)
     */
    public function buildSnapshot(Service $service, array $params, int $quantity): array
    {
        $format = $params['format'];
        $coverPaperId = $params['cover_paper_id'];
        $coverMode = $params['cover_mode'];
        $blockPaperId = $params['block_paper_id'];
        $blockEntries = $params['block_entries'] ?? [];

        // Load inventory items for cost calculation
        $coverPaper = $coverPaperId ? InventoryItem::find($coverPaperId) : null;
        $blockPaper = $blockPaperId ? InventoryItem::find($blockPaperId) : null;

        $coverPaperCost = $this->resolveAvgCost($coverPaper);
        $blockPaperCost = $this->resolveAvgCost($blockPaper);

        // Click costs from materials table
        $clickCosts = $this->getClickCosts();

        // ── Cover calculation (1 sheet per brochure) ──
        $coverClicks = $this->parseClicks($coverMode, $format);
        $coverClickCost = ($coverClicks['bw'] * $clickCosts['bw'])
                        + ($coverClicks['color'] * $clickCosts['color']);
        $coverUnitCost = $coverPaperCost + $coverClickCost;

        // ── Block calculation (per brochure, sum of all entries) ──
        $blockTotalSheets = 0;
        $blockTotalBwClicks = 0;
        $blockTotalColorClicks = 0;
        $blockUnitCost = 0;
        $blockSnapshotEntries = [];

        foreach ($blockEntries as $entry) {
            $sheets = (int) ($entry['sheets'] ?? 0);
            $mode = $entry['mode'] ?? '1+0';

            if ($sheets <= 0) {
                continue;
            }

            $clicks = $this->parseClicks($mode, $format);
            $entryBw = $clicks['bw'] * $sheets;
            $entryColor = $clicks['color'] * $sheets;

            $blockTotalSheets += $sheets;
            $blockTotalBwClicks += $entryBw;
            $blockTotalColorClicks += $entryColor;

            $entryCost = ($blockPaperCost * $sheets)
                       + ($entryBw * $clickCosts['bw'])
                       + ($entryColor * $clickCosts['color']);
            $blockUnitCost += $entryCost;

            $blockSnapshotEntries[] = [
                'mode'         => $mode,
                'sheets'       => $sheets,
                'bw_clicks'    => $entryBw,
                'color_clicks' => $entryColor,
                'cost'         => round($entryCost, 4),
            ];
        }

        // ── Totals per brochure ──
        $unitCost = $coverUnitCost + $blockUnitCost;

        // Total clicks per brochure
        $unitBwClicks = $coverClicks['bw'] + $blockTotalBwClicks;
        $unitColorClicks = $coverClicks['color'] + $blockTotalColorClicks;

        // ── Grand totals (× тираж) ──
        $totalCost = round($unitCost * $quantity, 2);
        $totalBwClicks = $unitBwClicks * $quantity;
        $totalColorClicks = $unitColorClicks * $quantity;

        // Commercial pricing: brochures are primarily internal-only.
        // If used in commercial order, apply 2× markup as safety net.
        $commercialMarkup = 2.0;
        $unitCommercial = round($unitCost * $commercialMarkup, 4);
        $totalCommercial = round($unitCommercial * $quantity, 2);

        return [
            'service_id'             => $service->id,
            'service_name'           => $service->name,
            'service_type'           => 'brochure',
            'quantity'               => $quantity,
            'unit_price_commercial'  => $unitCommercial,
            'unit_price_cost'        => round($unitCost, 4),
            'total_price_commercial' => $totalCommercial,
            'total_price_cost'       => $totalCost,
            'hardware_counters'      => [
                'bw_clicks'    => $totalBwClicks,
                'color_clicks' => $totalColorClicks,
                'riso_clicks'  => 0,
            ],
            'brochure_params' => [
                'format'  => $format,
                'binding' => 'staple',
                'cover'   => [
                    'paper_name'         => $coverPaper?->name ?? 'Не обрано',
                    'paper_inventory_id' => $coverPaperId,
                    'paper_cost'         => $coverPaperCost,
                    'print_mode'         => $coverMode,
                    'bw_clicks'          => $coverClicks['bw'],
                    'color_clicks'       => $coverClicks['color'],
                    'click_cost'         => round($coverClickCost, 4),
                    'unit_cost'          => round($coverUnitCost, 4),
                ],
                'block' => [
                    'paper_name'         => $blockPaper?->name ?? 'Не обрано',
                    'paper_inventory_id' => $blockPaperId,
                    'paper_cost'         => $blockPaperCost,
                    'total_sheets'       => $blockTotalSheets,
                    'entries'            => $blockSnapshotEntries,
                    'unit_cost'          => round($blockUnitCost, 4),
                ],
            ],
            // Inventory deduction data: what to deduct per brochure unit
            'inventory_deductions' => $this->buildDeductions(
                $coverPaperId, 1,
                $blockPaperId, $blockTotalSheets,
            ),
        ];
    }

    /**
     * Parse print mode into click counts per sheet.
     * Brochure format is the FINAL size (after folding):
     *   А4 brochure → printed on А3 paper → 2 clicks per side
     *   А5 brochure → printed on А4 paper → 1 click per side
     *
     * @return array{bw: int, color: int}
     */
    private function parseClicks(string $mode, string $brochureFormat): array
    {
        // А4 brochure = А3 paper = 2× clicks per side
        // А5 brochure = А4 paper = 1× click per side
        $multiplier = $brochureFormat === 'А4' ? 2 : 1;

        return match ($mode) {
            '1+0'   => ['bw' => 1 * $multiplier, 'color' => 0],
            '1+1'   => ['bw' => 2 * $multiplier, 'color' => 0],
            '4+0'   => ['bw' => 0, 'color' => 1 * $multiplier],
            '4+4'   => ['bw' => 0, 'color' => 2 * $multiplier],
            default => ['bw' => 0, 'color' => 0],
        };
    }

    /**
     * Get click costs from materials table (cached per request).
     *
     * @return array{bw: float, color: float}
     */
    private function getClickCosts(): array
    {
        return Material::clickCosts();
    }

    /**
     * Build inventory deduction list for auto-deduct on order completion.
     *
     * @return array<array{inventory_item_id: int|null, qty: int}>
     */
    private function buildDeductions(
        ?int $coverPaperId,
        int $coverSheets,
        ?int $blockPaperId,
        int $blockSheets,
    ): array {
        $deductions = [];

        if ($coverPaperId && $coverSheets > 0) {
            $deductions[] = [
                'inventory_item_id' => $coverPaperId,
                'qty'               => $coverSheets,
            ];
        }

        if ($blockPaperId && $blockSheets > 0) {
            // If same paper, merge
            if ($blockPaperId === $coverPaperId && count($deductions) > 0) {
                $deductions[0]['qty'] += $blockSheets;
            } else {
                $deductions[] = [
                    'inventory_item_id' => $blockPaperId,
                    'qty'               => $blockSheets,
                ];
            }
        }

        return $deductions;
    }

    /**
     * Resolve paper avg_cost with A3→A4 conversion fallback.
     *
     * When an A4 paper item has avg_cost=0 (not yet receipted) but is
     * convertible from A3, use the A3 source cost ÷ conversion_ratio.
     * Matches ServiceParameterOption::computeConsumablesCost() logic.
     */
    private function resolveAvgCost(?InventoryItem $item): float
    {
        if (! $item) {
            return 0.0;
        }

        $avgCost = (float) $item->avg_cost;

        if ($avgCost <= 0 && $item->convertible_from_id && $item->conversion_ratio > 0) {
            $source = $item->convertibleFrom;
            if ($source && (float) $source->avg_cost > 0) {
                $avgCost = (float) $source->avg_cost / (float) $item->conversion_ratio;
            }
        }

        return $avgCost;
    }
}
