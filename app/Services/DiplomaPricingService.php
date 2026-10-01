<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\Service;
use Illuminate\Support\Facades\Log;

/**
 * DiplomaPricingService
 *
 * Calculates pricing for diploma/supplement services.
 * The form sends sections with quantities and editable sheet breakdowns.
 *
 * Sections:
 *   - diplomas:        qty × 1 sheet A4 160g (4+0 color)
 *   - supplements:     [{type, qty, blocks: [{sheets, mode}]}]
 *   - academic_records: qty × 1 sheet A3 160g (4+4 color)
 *   - diploma_copies:  qty × 1 sheet A4 80g (fixed 1+0 bw)
 *   - supplement_copies: qty × blocks [{sheets, mode}] A4 80g (bw)
 *
 * Cost = paper_avg_cost × total_sheets + click_cost × total_clicks
 */
class DiplomaPricingService
{
    /**
     * Build JSONB snapshot for order_items.service_snapshot.
     *
     * @param  Service  $service  The diploma service
     * @param  array  $params  Form data from frontend
     * @param  int  $quantity  Always 1 for diploma (quantities are inside params)
     */
    public function buildSnapshot(Service $service, array $params, int $quantity): array
    {
        $clickCosts = $this->getClickCosts();

        // Load inventory items
        $paperA4_160 = $this->paper('Папір А4 160 г/м²');
        $paperA3_160 = $this->paper('Папір А3 160 г/м²');
        $paperA4_80 = $this->paper('Папір А4 80 г/м²');

        // Resolve costs with A3→A4 conversion fallback
        $costA4_160 = $this->resolveAvgCost($paperA4_160);
        $costA3_160 = $this->resolveAvgCost($paperA3_160);
        $costA4_80 = $this->resolveAvgCost($paperA4_80);

        $totalCost = 0;
        $totalBwClicks = 0;
        $totalColorClicks = 0;
        $deductions = [];

        // Helper: accumulate deductions per inventory item
        $addDeduction = function (?int $itemId, int $sheets) use (&$deductions) {
            if (! $itemId || $sheets <= 0) {
                return;
            }
            if (isset($deductions[$itemId])) {
                $deductions[$itemId] += $sheets;
            } else {
                $deductions[$itemId] = $sheets;
            }
        };

        // ── 1. Дипломи (A4 160g, color 4+0 = 1 click) ──
        $diplomaQty = (int) ($params['diplomas']['qty'] ?? 0);
        $diplomaCost = 0;
        if ($diplomaQty > 0) {
            $sheets = $diplomaQty * 1;  // 1 sheet per diploma
            $clicks = $diplomaQty * 1;  // 4+0 = 1 color click
            $diplomaCost = ($sheets * $costA4_160)
                         + ($clicks * $clickCosts['color']);
            $totalCost += $diplomaCost;
            $totalColorClicks += $clicks;
            $addDeduction($paperA4_160?->id, $sheets);
        }

        // ── 2. Додатки (A4 160g, color, mixed modes) ──
        $supplementSnapshots = [];
        foreach ($params['supplements'] ?? [] as $supp) {
            $suppQty = (int) ($supp['qty'] ?? 0);
            if ($suppQty <= 0) {
                continue;
            }

            $suppCost = 0;
            $suppSheets = 0;
            $suppColorClicks = 0;

            foreach ($supp['blocks'] ?? [] as $block) {
                $blockSheets = (int) ($block['sheets'] ?? 0);
                if ($blockSheets <= 0) {
                    continue;
                }

                $mode = $block['mode'] ?? '0+0';
                $clicks = $this->modeToColorClicks($mode);

                $totalSheetsForBlock = $blockSheets * $suppQty;
                $totalClicksForBlock = $clicks * $blockSheets * $suppQty;

                $blockCost = ($totalSheetsForBlock * $costA4_160)
                           + ($totalClicksForBlock * $clickCosts['color']);
                $suppCost += $blockCost;
                $suppSheets += $totalSheetsForBlock;
                $suppColorClicks += $totalClicksForBlock;
            }

            $totalCost += $suppCost;
            $totalColorClicks += $suppColorClicks;
            $addDeduction($paperA4_160?->id, $suppSheets);

            $supplementSnapshots[] = [
                'type'         => $supp['type'] ?? 'unknown',
                'label'        => $supp['label'] ?? '',
                'qty'          => $suppQty,
                'blocks'       => $supp['blocks'] ?? [],
                'cost'         => round($suppCost, 4),
                'sheets'       => $suppSheets,
                'color_clicks' => $suppColorClicks,
            ];
        }

        // ── 3. Академдовідки (A3 160g, color 4+4 = 2 clicks) ──
        $academicQty = (int) ($params['academic_records']['qty'] ?? 0);
        $academicCost = 0;
        if ($academicQty > 0) {
            $sheets = $academicQty * 1;  // 1 A3 sheet per record
            $clicks = $academicQty * 2;  // 4+4 = 2 color clicks
            $academicCost = ($sheets * $costA3_160)
                          + ($clicks * $clickCosts['color']);
            $totalCost += $academicCost;
            $totalColorClicks += $clicks;
            $addDeduction($paperA3_160?->id, $sheets);
        }

        // ── 4. Копії дипломів (A4 80g, bw, fixed 1+0 = 1 click per sheet) ──
        $diplomaCopiesQty = (int) ($params['copies']['diploma_copies']['qty'] ?? 0);
        $diplomaCopiesCost = 0;
        if ($diplomaCopiesQty > 0) {
            $sheets = $diplomaCopiesQty;  // 1 sheet per diploma copy
            $clicks = $diplomaCopiesQty;  // fixed 1+0 = 1 bw click
            $diplomaCopiesCost = ($sheets * $costA4_80)
                               + ($clicks * $clickCosts['bw']);
            $totalCost += $diplomaCopiesCost;
            $totalBwClicks += $clicks;
            $addDeduction($paperA4_80?->id, $sheets);
        }

        // ── 5. Копії додатків (A4 80g, bw, block-based per set) ──
        $suppCopiesQty = (int) ($params['copies']['supplement_copies']['qty'] ?? 0);
        $suppCopiesBlocks = $params['copies']['supplement_copies']['blocks'] ?? [];
        $suppCopiesCost = 0;
        $suppCopiesSheets = 0;
        $suppCopiesBwClicks = 0;
        if ($suppCopiesQty > 0) {
            foreach ($suppCopiesBlocks as $block) {
                $blockSheets = (int) ($block['sheets'] ?? 0);
                if ($blockSheets <= 0) {
                    continue;
                }

                $mode = $block['mode'] ?? '1+0';
                $clicks = $this->modeToBwClicks($mode);

                $totalSheetsForBlock = $blockSheets * $suppCopiesQty;
                $totalClicksForBlock = $clicks * $blockSheets * $suppCopiesQty;

                $blockCost = ($totalSheetsForBlock * $costA4_80)
                           + ($totalClicksForBlock * $clickCosts['bw']);
                $suppCopiesCost += $blockCost;
                $suppCopiesSheets += $totalSheetsForBlock;
                $suppCopiesBwClicks += $totalClicksForBlock;
            }
            $totalCost += $suppCopiesCost;
            $totalBwClicks += $suppCopiesBwClicks;
            $addDeduction($paperA4_80?->id, $suppCopiesSheets);
        }

        $totalCost = round($totalCost, 2);

        return [
            'service_id'             => $service->id,
            'service_name'           => $service->name,
            'service_type'           => 'diploma',
            'quantity'               => $quantity,
            'unit_price_commercial'  => 0,
            'unit_price_cost'        => round($totalCost, 4),
            'total_price_commercial' => 0,
            'total_price_cost'       => $totalCost,
            'hardware_counters'      => [
                'bw_clicks'    => $totalBwClicks,
                'color_clicks' => $totalColorClicks,
                'riso_clicks'  => 0,
            ],
            'diploma_params' => [
                'diplomas' => [
                    'qty'  => $diplomaQty,
                    'cost' => round($diplomaCost, 4),
                ],
                'supplements'      => $supplementSnapshots,
                'academic_records' => [
                    'qty'  => $academicQty,
                    'cost' => round($academicCost, 4),
                ],
                'copies' => [
                    'diploma_copies' => [
                        'qty'  => $diplomaCopiesQty,
                        'mode' => '1+0',
                        'cost' => round($diplomaCopiesCost, 4),
                    ],
                    'supplement_copies' => [
                        'qty'    => $suppCopiesQty,
                        'blocks' => $suppCopiesBlocks,
                        'sheets' => $suppCopiesSheets,
                        'cost'   => round($suppCopiesCost, 4),
                    ],
                ],
                'paper_costs' => [
                    'a4_160' => $costA4_160,
                    'a3_160' => $costA3_160,
                    'a4_80'  => $costA4_80,
                ],
            ],
            'inventory_deductions' => collect($deductions)
                ->map(fn ($qty, $id) => ['inventory_item_id' => $id, 'qty' => $qty])
                ->values()
                ->all(),
        ];
    }

    /**
     * Color clicks for a print mode (per sheet).
     * 4+4 = 2, 4+0 = 1, 0+0 = 0
     */
    private function modeToColorClicks(string $mode): int
    {
        return match ($mode) {
            '4+4'   => 2,
            '4+0'   => 1,
            default => 0,
        };
    }

    /**
     * BW clicks for a copy mode (per sheet).
     * 1+1 = 2, 1+0 = 1
     */
    private function modeToBwClicks(string $mode): int
    {
        return match ($mode) {
            '1+1'   => 2,
            '1+0'   => 1,
            default => 1,
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
     * The paper this service prices on, found by the name written above.
     *
     * Three constructors reach into the same stock room and hold on to it three
     * different ways: the brochure by **key** (`find($id)`, off the form), RISO
     * by a name out of `config/riso.php`, and this one by string literals. A
     * lookup that misses used to cost nothing and say nothing —
     * `resolveAvgCost(null)` is `0.0` and `addDeduction(null, …)` returns early,
     * so the sheet became free and never left the shelf.
     *
     * Two things go wrong, and they need different answers:
     *
     *  - **the row was deactivated** — an ordinary stock-room edit that says
     *    «stop offering this», not «this sheet is now free». The diplomas are
     *    printed on paper either way, so the row is still used for the price and
     *    still deducted. `getClickCosts()` in this same file already takes that
     *    view of a missing material, falling back to 0.12 / 0.60 rather than to
     *    nothing;
     *  - **the row was renamed** — nothing is left to find, and a silent zero
     *    reads exactly like a legitimately free sheet. So it is said out loud.
     */
    private function paper(string $name): ?InventoryItem
    {
        $item = InventoryItem::where('name', $name)->where('is_active', true)->first()
            ?? InventoryItem::where('name', $name)->first();

        if (! $item) {
            Log::warning("Diploma pricing found no stock item named «{$name}» — its sheets are priced at zero and not deducted.");
        }

        return $item;
    }

    /**
     * Resolve paper avg_cost with A3→A4 conversion fallback.
     *
     * When a paper item has avg_cost=0 (not yet receipted) but is
     * convertible from a source (e.g. A3), use source cost ÷ conversion_ratio.
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
