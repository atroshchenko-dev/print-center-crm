<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DashboardController
 *
 * Renders the dashboard with shift status and analytics charts.
 */
class DashboardController extends Controller
{
    public function __invoke(): Response|RedirectResponse
    {
        $shift = Shift::with('opener')->current()->first();

        if (! $shift) {
            // This is a pass-through, not a destination. Anything flashed by
            // whoever sent the user here — "shift closed", most of all — would
            // otherwise be aged out on this request and never reach a page
            // that renders it.
            session()->reflash();

            return redirect()->route('shifts.open.form');
        }

        // Only compute charts for admin users (cached 5 min)
        $chartData = null;
        if (auth()->user()->isAdmin()) {
            $cacheEnabled = Setting::getValue('cache_enabled', true);

            $chartData = $cacheEnabled
                ? Cache::remember('dashboard:charts', 300, fn () => $this->buildChartData())
                : $this->buildChartData();
        }

        return Inertia::render('Dashboard', [
            'shift'     => $shift,
            'chartData' => $chartData,
        ]);
    }

    /**
     * Build last 14 days of financial and order count data, split by type.
     *
     * Internal orders → total_cost (собівартість)
     * Commercial orders → total_commercial (дохід)
     */
    private function buildChartData(): array
    {
        // Kyiv days, not UTC ones. `DATE(created_at)` buckets by UTC date, so
        // everything taken in between midnight and 03:00 Kyiv was drawn on the
        // previous day's bar — the same disagreement the summary below fixes.
        $from = today('Europe/Kyiv')->subDays(13)->startOfDay()->utc();
        $to   = today('Europe/Kyiv')->endOfDay()->utc();

        $dailyStats = Order::select(
                DB::raw("DATE(created_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Kyiv') as day"),
                'type',
                DB::raw("COUNT(*) as count"),
                DB::raw("COALESCE(SUM(total_commercial), 0) as revenue"),
                DB::raw("COALESCE(SUM(total_cost), 0) as cost"),
            )
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->operational()
            ->whereNotIn('status', [OrderStatus::Cancelled->value])
            ->groupBy('day', 'type')
            ->orderBy('day')
            ->get();

        $labels = [];
        $internalCounts = [];
        $commercialCounts = [];
        $internalCost = [];
        $commercialRevenue = [];

        for ($i = 13; $i >= 0; $i--) {
            $date = today('Europe/Kyiv')->subDays($i);
            $key = $date->format('Y-m-d');
            $labels[] = $date->format('d.m');

            $intDay = $dailyStats->where('day', $key)->where('type', OrderType::Internal->value)->first();
            $comDay = $dailyStats->where('day', $key)->where('type', OrderType::Commercial->value)->first();

            $internalCounts[] = $intDay?->count ?? 0;
            $commercialCounts[] = $comDay?->count ?? 0;
            $internalCost[] = round((float) ($intDay?->cost ?? 0), 2);
            $commercialRevenue[] = round((float) ($comDay?->revenue ?? 0), 2);
        }

        // DATE(created_at) is a UTC date; today('Europe/Kyiv') is a Kyiv one.
        // Between midnight and 03:00 Kyiv the two disagree, and the day's
        // first orders counted as zero. Bound the Kyiv day instead.
        // Week and month start on Kyiv midnight too. Left on `now()` they cut
        // the day at 03:00 Kyiv, so the orders of the 1st taken before 03:00
        // counted towards the month before — the tile said one thing and the
        // report for the same period said another.
        $todayStart = today('Europe/Kyiv')->startOfDay()->utc();
        $todayEnd   = today('Europe/Kyiv')->endOfDay()->utc();
        $weekAgo    = today('Europe/Kyiv')->subDays(7)->startOfDay()->utc();
        $monthStart = today('Europe/Kyiv')->startOfMonth()->utc();
        $lastMonthStart = today('Europe/Kyiv')->subMonth()->startOfMonth()->utc();

        $int = OrderType::Internal->value;
        $com = OrderType::Commercial->value;

        $summary = DB::selectOne("
            SELECT
                COUNT(*) FILTER (WHERE created_at >= ? AND created_at <= ? AND type = ?)                           AS today_int,
                COALESCE(SUM(total_cost) FILTER (WHERE created_at >= ? AND type = ?), 0)                           AS week_int_cost,
                COALESCE(SUM(total_commercial) FILTER (WHERE created_at >= ? AND type = ?), 0)                     AS week_int_commercial,
                COUNT(*) FILTER (WHERE created_at >= ? AND type = ?)                                               AS month_int,
                COALESCE(SUM(total_cost) FILTER (WHERE created_at >= ? AND type = ?), 0)                           AS month_int_cost,
                COALESCE(SUM(total_commercial) FILTER (WHERE created_at >= ? AND type = ?), 0)                     AS month_int_commercial,
                COUNT(*) FILTER (WHERE created_at >= ? AND created_at < ? AND type = ?)                            AS last_month_int,
                COALESCE(SUM(total_cost) FILTER (WHERE created_at >= ? AND created_at < ? AND type = ?), 0)        AS last_month_int_cost,
                COALESCE(SUM(total_commercial) FILTER (WHERE created_at >= ? AND created_at < ? AND type = ?), 0)  AS last_month_int_commercial,

                COUNT(*) FILTER (WHERE created_at >= ? AND created_at <= ? AND type = ?)                           AS today_com,
                COALESCE(SUM(total_commercial) FILTER (WHERE created_at >= ? AND type = ?), 0)                     AS week_com_rev,
                COALESCE(SUM(total_cost) FILTER (WHERE created_at >= ? AND type = ?), 0)                           AS week_com_cost,
                COUNT(*) FILTER (WHERE created_at >= ? AND type = ?)                                               AS month_com,
                COALESCE(SUM(total_commercial) FILTER (WHERE created_at >= ? AND type = ?), 0)                     AS month_com_rev,
                COALESCE(SUM(total_cost) FILTER (WHERE created_at >= ? AND type = ?), 0)                           AS month_com_cost,
                COUNT(*) FILTER (WHERE created_at >= ? AND created_at < ? AND type = ?)                            AS last_month_com,
                COALESCE(SUM(total_commercial) FILTER (WHERE created_at >= ? AND created_at < ? AND type = ?), 0)  AS last_month_com_rev,
                COALESCE(SUM(total_cost) FILTER (WHERE created_at >= ? AND created_at < ? AND type = ?), 0)        AS last_month_com_cost
            FROM orders
            WHERE status != ?
              AND deleted_at IS NULL
              AND is_backdated = false
        ", [
            $todayStart, $todayEnd, $int, $weekAgo, $int, $weekAgo, $int, $monthStart, $int, $monthStart, $int, $monthStart, $int,
            $lastMonthStart, $monthStart, $int, $lastMonthStart, $monthStart, $int, $lastMonthStart, $monthStart, $int,
            $todayStart, $todayEnd, $com, $weekAgo, $com, $weekAgo, $com, $monthStart, $com, $monthStart, $com, $monthStart, $com,
            $lastMonthStart, $monthStart, $com, $lastMonthStart, $monthStart, $com, $lastMonthStart, $monthStart, $com,
            OrderStatus::Cancelled->value,
        ]);

        return [
            'labels'            => $labels,
            'internalCounts'    => $internalCounts,
            'commercialCounts'  => $commercialCounts,
            'internalCost'      => $internalCost,
            'commercialRevenue' => $commercialRevenue,
            // Internal summary (cost + commercial equivalent for savings)
            'todayInt'              => (int) $summary->today_int,
            'weekIntCost'           => round((float) $summary->week_int_cost, 2),
            'weekIntCommercial'     => round((float) $summary->week_int_commercial, 2),
            'monthInt'              => (int) $summary->month_int,
            'monthIntCost'          => round((float) $summary->month_int_cost, 2),
            'monthIntCommercial'    => round((float) $summary->month_int_commercial, 2),
            'lastMonthInt'          => (int) $summary->last_month_int,
            'lastMonthIntCost'      => round((float) $summary->last_month_int_cost, 2),
            'lastMonthIntCommercial'=> round((float) $summary->last_month_int_commercial, 2),
            // Commercial summary (revenue + cost for profit)
            'todayCom'              => (int) $summary->today_com,
            'weekComRev'            => round((float) $summary->week_com_rev, 2),
            'weekComCost'           => round((float) $summary->week_com_cost, 2),
            'monthCom'              => (int) $summary->month_com,
            'monthComRev'           => round((float) $summary->month_com_rev, 2),
            'monthComCost'          => round((float) $summary->month_com_cost, 2),
            'lastMonthCom'          => (int) $summary->last_month_com,
            'lastMonthComRev'       => round((float) $summary->last_month_com_rev, 2),
            'lastMonthComCost'      => round((float) $summary->last_month_com_cost, 2),
        ];
    }
}
