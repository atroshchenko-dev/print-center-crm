<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Services\ProcurementAdvisorService;
use App\Services\TelegramService;
use Illuminate\Console\Command;

/**
 * Inventory Forecast — proactive stock depletion alerting.
 *
 * Analyzes the last 30 days of consumption movements to calculate
 * average daily consumption per inventory item. Alerts via Telegram
 * when any item is projected to deplete within the configured threshold.
 *
 * Consumption is auto_deduct plus convert_out: cutting a source sheet
 * for another item (A3 → A4) drains it just as surely as an order does,
 * and for a pure source item it is the only movement there is.
 *
 * An item backed by a convertible source counts the source's sheets as
 * cover, because the deduction path cuts the source on its own before it
 * ever runs dry — its forecast has to see the same reserve, or it cries
 * «сьогодні» about an item production will quietly keep printing on.
 *
 * Scheduled daily at 08:00 Europe/Kyiv.
 */
class InventoryForecastCommand extends Command
{
    protected $signature = 'inventory:forecast
                            {--days=3 : Alert threshold — warn if stock depletes within this many days}
                            {--period=30 : Lookback period in days for consumption calculation}';

    protected $description = 'Forecast inventory depletion and alert via Telegram';

    public function handle(TelegramService $telegram, ProcurementAdvisorService $advisor): int
    {
        $thresholdDays = (int) $this->option('days');
        $lookbackDays = (int) $this->option('period');

        // The lookback is the divisor for the daily rate below.
        if ($lookbackDays < 1) {
            $this->error('--period must be at least 1 day.');

            return self::FAILURE;
        }

        $this->info("Forecasting with {$lookbackDays}-day lookback, {$thresholdDays}-day threshold...");

        // Average daily consumption comes from the shared advisor — the same
        // sum the «Закупівлі» tab shows, so forecast and tab cannot diverge.
        $consumption = $advisor->consumptionRates($lookbackDays);

        if ($consumption->isEmpty()) {
            $this->info('No consumption data found in the lookback period.');

            return self::SUCCESS;
        }

        // Load active inventory items that have consumption
        $items = InventoryItem::where('is_active', true)
            ->whereIn('id', $consumption->keys())
            ->with('convertibleFrom')
            ->get();

        $criticalItems = [];

        foreach ($items as $item) {
            $totalUsed = $consumption[$item->id] ?? 0.0;
            $dailyRate = $totalUsed / $lookbackDays;
            $currentQty = (float) $item->current_quantity;

            if ($dailyRate <= 0) {
                continue;
            }

            // Sheets still on the convertible source's shelf are cover: the
            // deduction path cuts them on its own (autoConvertIfNeeded). A
            // soft-deleted source is no reserve — the relation's SoftDeletes
            // scope hides it here exactly as it hides it from the cutter.
            $source = $item->convertibleFrom;
            $ratio = (float) $item->conversion_ratio;
            $reserveQty = $source && $ratio > 0
                ? max(0.0, (float) $source->current_quantity)
                : 0.0;

            $effectiveQty = $currentQty + $reserveQty * $ratio;
            $daysRemaining = (int) floor($effectiveQty / $dailyRate);

            $this->line(sprintf(
                '  %s: %.0f шт.%s, %.1f/день → %d днів',
                $item->name,
                $currentQty,
                $reserveQty > 0 ? sprintf(' (+%.0f арк. «%s»)', $reserveQty, $source->name) : '',
                $dailyRate,
                $daysRemaining,
            ));

            if ($daysRemaining <= $thresholdDays) {
                $critical = [
                    'name'       => $item->name,
                    'current'    => $currentQty,
                    'daily_rate' => round($dailyRate, 1),
                    'days'       => max(0, $daysRemaining),
                ];

                if ($reserveQty > 0) {
                    $critical['reserve_qty'] = $reserveQty;
                    $critical['reserve_name'] = $source->name;
                }

                $criticalItems[] = $critical;
            }
        }

        if (! empty($criticalItems)) {
            $this->warn(count($criticalItems).' item(s) will deplete within '.$thresholdDays.' days!');
            $telegram->stockForecast($criticalItems);
        } else {
            $this->info('All items are above the depletion threshold. ✅');
        }

        return self::SUCCESS;
    }
}
