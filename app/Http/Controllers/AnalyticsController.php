<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Controllers\Concerns\ParsesDateRange;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AnalyticsController
 *
 * Provides analytics dashboard with key business metrics:
 * - Orders per day (trend)
 * - Top services by quantity
 * - Revenue by type (internal vs commercial)
 * - Operator productivity
 */
class AnalyticsController extends Controller
{
    use ParsesDateRange;
    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        // The filter fields are filled from these. The bounds are UTC instants
        // of Kyiv midnights, so formatted straight off, a range asked for from
        // 1 July came back as "30 June" — and submitting the form unchanged
        // then really did ask for June.
        [$fromLabel, $toLabel] = $this->dateRangeLabel($from, $to, 'Y-m-d');

        return Inertia::render('Analytics/Index', [
            'filters' => [
                'from' => $fromLabel,
                'to'   => $toLabel,
            ],
            'ordersPerDay'       => $this->ordersPerDay($from, $to),
            'topServices'        => $this->topServices($from, $to),
            'revenueByType'      => $this->revenueByType($from, $to),
            'operatorStats'      => $this->operatorStats($from, $to),
            'summary'            => $this->summary($from, $to),
        ]);
    }

    /**
     * Orders per day (line chart).
     */
    private function ordersPerDay(Carbon $from, Carbon $to): array
    {
        // The range arrives as Kyiv days converted to UTC; the buckets have to
        // be the same days. `DATE(created_at)` cuts on the UTC date, so orders
        // taken between midnight and 03:00 Kyiv — the shift runs to the 03:00
        // auto-close — were drawn on the previous day, and the first and last
        // days of the range were partial.
        //
        // Cancelled work is left out, by the owner's decision on 2026-07-28:
        // "Замовлення по днях" reads as work done, not as intake. That is what
        // topServices() and revenueByType() on the same page already meant.
        return Order::whereBetween('created_at', [$from, $to])
            ->operational()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->select(
                DB::raw("DATE(created_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Kyiv') as date"),
                DB::raw("count(*) as count"),
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    /**
     * Top 10 services by total quantity.
     */
    private function topServices(Carbon $from, Carbon $to): array
    {
        // Bounded by the date of the order, like every other figure here. On
        // the line item's own date, a position added to an order the next day
        // fell into a different period than the order it belongs to.
        return OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.is_backdated', false)
            // Cancelled work was never done. Every other figure on this page
            // excludes it; this one counted it and inflated the ranking.
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->select('order_items.service_name', DB::raw('SUM(order_items.quantity) as total_qty'), DB::raw('COUNT(DISTINCT order_items.order_id) as order_count'))
            ->groupBy('order_items.service_name')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * Revenue grouped by order type.
     */
    private function revenueByType(Carbon $from, Carbon $to): array
    {
        return Order::whereBetween('created_at', [$from, $to])
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->operational()
            ->select('type', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_cost) as total_cost'), DB::raw('SUM(total_commercial) as total_commercial'))
            ->groupBy('type')
            ->get()
            ->toArray();
    }

    /**
     * Operator productivity (orders per user).
     */
    private function operatorStats(Carbon $from, Carbon $to): array
    {
        return Order::whereBetween('orders.created_at', [$from, $to])
            ->where('orders.is_backdated', false)
            // Cancelled work is not work. This was the third figure on the
            // page still counting it, after the service ranking and the daily
            // chart — and here it also credited the cost of orders that were
            // never produced to the operator who took them.
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->select('users.name', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(orders.total_cost) as total_cost'))
            // By id, not by name: two operators who share a name are two
            // operators, and merging them credits one for the other's work.
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('order_count')
            ->get()
            ->toArray();
    }

    /**
     * Summary totals.
     */
    private function summary(Carbon $from, Carbon $to): array
    {
        $makeBase = fn () => Order::whereBetween('created_at', [$from, $to])->operational();
        $makeActive = fn () => $makeBase()->where('status', '!=', OrderStatus::Cancelled->value);

        $totalCostInt      = (float) $makeActive()->where('type', 'internal')->sum('total_cost');
        $totalCommercialInt = (float) $makeActive()->where('type', 'internal')->sum('total_commercial');
        $totalCostCom      = (float) $makeActive()->where('type', 'commercial')->sum('total_cost');
        $totalCommercialCom = (float) $makeActive()->where('type', 'commercial')->sum('total_commercial');

        return [
            'total_orders'      => $makeBase()->count(),
            'cancelled'         => $makeBase()->where('status', OrderStatus::Cancelled->value)->count(),
            'total_cost'        => $totalCostInt + $totalCostCom,
            'total_commercial'  => $totalCommercialInt + $totalCommercialCom,
            'avg_per_day'       => round($makeActive()->count() / max(1, $from->diffInDays($to)), 1),
            // Per-type financial metrics
            'savings'           => $totalCommercialInt - $totalCostInt,
            'profit'            => $totalCommercialCom - $totalCostCom,
            'cost_int'          => $totalCostInt,
            'commercial_int'    => $totalCommercialInt,
            'cost_com'          => $totalCostCom,
            'commercial_com'    => $totalCommercialCom,
        ];
    }
}
