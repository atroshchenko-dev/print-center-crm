<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes what to buy, how much, and at what estimated cost, for the
 * «Закупівлі» tab on /admin/inventory.
 *
 * Consumption is auto_deduct plus convert_out — the same rule
 * inventory:forecast alerts by, and both take it from this method so the
 * tab and the Telegram forecast can never disagree.
 */
class ProcurementAdvisorService
{
    public const LOOKBACK_DAYS = 30;

    public const DEFAULT_HORIZON_DAYS = 60;

    private const CRITICAL_DAYS = 3;

    private const NO_HISTORY_TOP_UP_FACTOR = 2.0;

    private const PAIR_COLORS = ['Червоний', 'Синій'];

    /**
     * Total consumption per item over the lookback window.
     *
     * @return Collection<int, float> inventory_item_id => units consumed
     */
    public function consumptionRates(int $lookbackDays = self::LOOKBACK_DAYS): Collection
    {
        return InventoryMovement::query()
            ->select('inventory_item_id', DB::raw('SUM(ABS(quantity)) as total_used'))
            ->whereIn('type', ['auto_deduct', 'convert_out'])
            ->where('created_at', '>=', now()->subDays($lookbackDays))
            ->groupBy('inventory_item_id')
            ->pluck('total_used', 'inventory_item_id')
            ->map(fn ($total) => (float) $total);
    }

    /**
     * Full payload for the «Закупівлі» tab (spec §4.3).
     *
     * @return array<string, mixed>
     */
    public function advise(int $horizonDays = self::DEFAULT_HORIZON_DAYS): array
    {
        $consumption = $this->consumptionRates();

        $items = InventoryItem::query()
            ->with(['category', 'convertibleFrom'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $regular = $this->adviseRegular(
            $items->reject(fn (InventoryItem $i) => in_array(
                $i->category?->name,
                [InventoryCategory::HARD_BINDING_PAIRS_SLUG, InventoryCategory::CONSUMABLES_SLUG],
                true,
            )),
            $consumption,
            $horizonDays,
        );

        $pairs = $this->advisePairs(
            $items->filter(fn (InventoryItem $i) => $i->category?->name === InventoryCategory::HARD_BINDING_PAIRS_SLUG),
            $consumption,
        );

        $toners = $this->adviseToners(
            $items->filter(fn (InventoryItem $i) => $i->category?->name === InventoryCategory::CONSUMABLES_SLUG),
        );

        return [
            'horizon_days' => $horizonDays,
            'summary'      => $this->summarize($regular, $pairs, $toners),
            'regular'      => $regular,
            'pairs'        => $pairs,
            'toners'       => $toners,
        ];
    }

    /**
     * Replenishment rows for ordinary categories: cover N days of measured
     * consumption; with no history, top up to twice the minimum once the
     * stock is at or under it.
     *
     * @param  Collection<int, InventoryItem>  $items
     * @param  Collection<int, float>  $consumption
     * @return list<array<string, mixed>>
     */
    private function adviseRegular(Collection $items, Collection $consumption, int $horizonDays): array
    {
        $rows = [];

        foreach ($items as $item) {
            $dailyRate = ($consumption[$item->id] ?? 0.0) / self::LOOKBACK_DAYS;
            $currentQty = (float) $item->current_quantity;
            $minQty = (float) $item->min_quantity;

            // Sheets on the convertible source's shelf are cover the cutter
            // spends on its own — the same reserve inventory:forecast counts.
            $source = $item->convertibleFrom;
            $ratio = (float) $item->conversion_ratio;
            $reserveQty = $source && $ratio > 0 ? max(0.0, (float) $source->current_quantity) : 0.0;
            $effectiveQty = $currentQty + $reserveQty * $ratio;

            if ($dailyRate > 0) {
                $recommended = (int) ceil($dailyRate * $horizonDays - $effectiveQty);
                $reason = 'forecast';
            } elseif ($minQty > 0 && $currentQty <= $minQty) {
                $recommended = (int) ceil(self::NO_HISTORY_TOP_UP_FACTOR * $minQty - $currentQty);
                $reason = 'min_fallback';
            } else {
                continue;
            }

            if ($recommended <= 0) {
                continue;
            }

            $avgCost = (float) $item->avg_cost;

            $rows[] = [
                'id'               => $item->id,
                'name'             => $item->name,
                'category_name'    => $item->category ? $item->category->name : '',
                'unit'             => $item->unit,
                'current_quantity' => $currentQty,
                'reserve'          => $reserveQty > 0 ? ['name' => $source->name, 'qty' => $reserveQty] : null,
                'daily_rate'       => round($dailyRate, 2),
                'days_left'        => $dailyRate > 0 ? (int) floor($effectiveQty / $dailyRate) : null,
                'min_quantity'     => $minQty,
                'recommended_qty'  => $recommended,
                'est_cost'         => $avgCost > 0 ? round($recommended * $avgCost, 2) : null,
                'reason'           => $reason,
            ];
        }

        usort($rows, fn (array $a, array $b) => ($a['days_left'] ?? PHP_INT_MAX) <=> ($b['days_left'] ?? PHP_INT_MAX)
            ?: strcmp($a['category_name'], $b['category_name']));

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $regular
     * @param  array{all_empty: bool, colors: list<array<string, mixed>>}  $pairs
     * @param  array{refill: list<array<string, mixed>>, refill_total_cost: float, low_full: list<array<string, mixed>>}  $toners
     * @return array{total_cost: float, items_count: int, critical_count: int}
     */
    private function summarize(array $regular, array $pairs, array $toners): array
    {
        $coverRecs = collect($pairs['colors'])->pluck('buy_covers')->filter();

        return [
            'total_cost' => round(
                collect($regular)->sum(fn (array $r) => $r['est_cost'] ?? 0)
                + $coverRecs->sum(fn (array $c) => $c['est_cost'] ?? 0)
                + $toners['refill_total_cost'],
                2,
            ),
            'items_count'    => count($regular) + $coverRecs->count() + count($toners['refill']),
            'critical_count' => collect($regular)
                ->filter(fn (array $r) => $r['days_left'] !== null && $r['days_left'] <= self::CRITICAL_DAYS)
                ->count(),
        ];
    }

    /**
     * The channel+cover line is being phased out: no replenishment by
     * consumption or minimums — only completing pairs. A cover fits any
     * channel size of its own color, so the balance is per color; when
     * channels run short the sizes are a human's call, the block only
     * shows stock and 30-day usage as a hint.
     *
     * @param  Collection<int, InventoryItem>  $items  Active items of the pairs category
     * @param  Collection<int, float>  $consumption
     * @return array{all_empty: bool, colors: list<array<string, mixed>>}
     */
    private function advisePairs(Collection $items, Collection $consumption): array
    {
        $colors = [];

        foreach (self::PAIR_COLORS as $color) {
            $ofColor = $items->filter(fn (InventoryItem $i) => str_contains($i->name, "({$color})"));
            if ($ofColor->isEmpty()) {
                continue;
            }

            [$covers, $channels] = $ofColor->partition(
                fn (InventoryItem $i) => str_starts_with($i->name, 'Обкладинка'),
            );

            $channelsTotal = (float) $channels->sum('current_quantity');
            $coversTotal = (float) $covers->sum('current_quantity');
            $cover = $covers->first();

            $coversDeficit = (int) ceil(max(0, $channelsTotal - $coversTotal));
            $coverCost = $cover !== null ? (float) ($cover->avg_cost ?? 0) : 0.0;

            $colors[] = [
                'color'          => $color,
                'channels_total' => $channelsTotal,
                'covers_total'   => $coversTotal,
                'ready_sets'     => (int) floor(min($channelsTotal, $coversTotal)),
                'buy_covers'     => $coversDeficit > 0 && $cover !== null
                    ? [
                        'id'       => $cover->id,
                        'name'     => $cover->name,
                        'qty'      => $coversDeficit,
                        'est_cost' => $coverCost > 0 ? round($coversDeficit * $coverCost, 2) : null,
                    ]
                    : null,
                'channel_deficit' => $coversTotal > $channelsTotal ? (int) ceil($coversTotal - $channelsTotal) : null,
                'channel_sizes'   => $channels
                    ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                    ->values()
                    ->map(fn (InventoryItem $i) => [
                        'id'       => $i->id,
                        'name'     => $i->name,
                        'qty'      => (float) $i->current_quantity,
                        'used_30d' => round($consumption[$i->id] ?? 0.0, 1),
                    ])
                    ->all(),
            ];
        }

        return [
            'all_empty' => (float) $items->sum('current_quantity') <= 0,
            'colors'    => $colors,
        ];
    }

    /**
     * Toners live on their own tab with a refill cycle, so «procurement»
     * here means two things: empties waiting for a paid refill, and a
     * heads-up when full ones run at or under the minimum. How many NEW
     * cartridges to buy is a human's call — never recommended.
     *
     * @param  Collection<int, InventoryItem>  $items  Active items of the consumables category
     * @return array{refill: list<array<string, mixed>>, refill_total_cost: float, low_full: list<array<string, mixed>>}
     */
    private function adviseToners(Collection $items): array
    {
        $refill = $items
            ->filter(fn (InventoryItem $i) => (float) $i->empty_quantity > 0)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values()
            ->map(function (InventoryItem $i) {
                $refillCost = (float) ($i->refill_cost ?? 0);

                return [
                    'id'             => $i->id,
                    'name'           => $i->name,
                    'empty_quantity' => (float) $i->empty_quantity,
                    'refill_cost'    => $refillCost,
                    'est_cost'       => $refillCost > 0 ? round((float) $i->empty_quantity * $refillCost, 2) : null,
                ];
            })
            ->all();

        return [
            'refill'            => $refill,
            'refill_total_cost' => round(array_sum(array_map(fn (array $r) => $r['est_cost'] ?? 0, $refill)), 2),
            'low_full'          => $items
                ->filter(fn (InventoryItem $i) => (float) $i->min_quantity > 0
                    && (float) $i->current_quantity <= (float) $i->min_quantity)
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->values()
                ->map(fn (InventoryItem $i) => [
                    'id'               => $i->id,
                    'name'             => $i->name,
                    'current_quantity' => (float) $i->current_quantity,
                    'empty_quantity'   => (float) $i->empty_quantity,
                    'min_quantity'     => (float) $i->min_quantity,
                ])
                ->all(),
        ];
    }
}
