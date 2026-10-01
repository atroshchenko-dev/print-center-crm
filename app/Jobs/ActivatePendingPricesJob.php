<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PriceSchedulerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ActivatePendingPricesJob
 *
 * Activates delayed material prices at 00:01 Europe/Kyiv.
 * DST-aware via Carbon timezone configuration.
 *
 * TZ §3.2: price changes activate in off-hours to avoid conflicts
 * with active operator sessions.
 */
class ActivatePendingPricesJob implements ShouldQueue
{
    use Queueable;

    public function handle(PriceSchedulerService $priceScheduler): void
    {
        $count = $priceScheduler->activatePendingPrices();
        Log::info("ActivatePendingPricesJob: {$count} price(s) activated.");
    }
}
