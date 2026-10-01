<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Material;
use Illuminate\Support\Facades\Log;

/**
 * PriceSchedulerService
 *
 * Activates pending (delayed) prices.
 * Called by ActivatePendingPricesJob at 00:01 Europe/Kyiv (DST-aware).
 *
 * TZ §3.2: Delayed price activation feature.
 * Price change activates automatically in off-hours to avoid conflicts
 * with orders being created by operators.
 */
class PriceSchedulerService
{
    /**
     * Activate all materials whose pending_activated_at has passed.
     *
     * @return int Number of prices activated
     */
    public function activatePendingPrices(): int
    {
        $pending = Material::whereNotNull('pending_click_cost')
            ->whereNotNull('pending_activated_at')
            ->where('pending_activated_at', '<=', now())
            ->get();

        $count = 0;

        foreach ($pending as $material) {
            $oldCost = $material->click_cost;
            $newCost = $material->pending_click_cost;

            $material->update([
                'click_cost'           => $newCost,
                'pending_click_cost'   => null,
                'pending_activated_at' => null,
            ]);

            Log::info("PriceScheduler: Material #{$material->id} '{$material->name}' " .
                "price updated from {$oldCost} to {$newCost}");

            AuditLog::record(
                eventType: 'price_activated',
                user: null,
                description: "Material '{$material->name}' click cost changed from {$oldCost} to {$newCost} (scheduled)",
                shiftId: null,
                meta: [
                    'material_id' => $material->id,
                    'old_cost'    => $oldCost,
                    'new_cost'    => $newCost,
                ],
            );

            $count++;
        }

        return $count;
    }
}
