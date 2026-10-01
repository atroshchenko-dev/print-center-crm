<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Shared grouping for the two accounting exports that print one sheet per service.
 *
 * Both used to group whole orders by the service of their *first* item. The
 * category sheet then kept only the items whose service matched the group key,
 * so an order that bought two services was filed under the first one and
 * everything from the second appeared on no sheet at all — while the summary
 * still counted the whole order's cost against the first category. The detail
 * rows of a sheet and its own summary line did not add up.
 *
 * An order belongs here once per service it actually contains. Each sheet then
 * prints exactly its own items, and no item is left without a sheet.
 */
trait GroupsOrdersByService
{
    /** Bucket for an order that has no items at all. */
    private const NO_SERVICE = 'Інше';

    /**
     * @param  Collection<int, Order>  $orders
     * @return Collection<string, Collection<int, Order>>
     */
    private function groupOrdersByService(Collection $orders): Collection
    {
        $grouped = [];

        foreach ($orders as $order) {
            $services = $order->items->pluck('service_name')->unique();

            if ($services->isEmpty()) {
                $services = collect([self::NO_SERVICE]);
            }

            foreach ($services as $service) {
                $grouped[$service][] = $order;
            }
        }

        return collect($grouped)
            ->map(static fn (array $ordersOfService) => collect($ordersOfService))
            ->sortKeys();
    }
}
