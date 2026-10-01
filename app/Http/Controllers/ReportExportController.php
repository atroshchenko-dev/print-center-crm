<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\AuditLogExport;
use App\Exports\CashFlowExport;
use App\Exports\CommercialReportExport;
use App\Exports\CounterAnalyticsExport;
use App\Exports\InternalReportExport;
use App\Http\Controllers\Concerns\ParsesDateRange;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ReportExportController
 *
 * Handles XLSX export for all report types.
 * Extracted from ReportController for SRP compliance.
 *
 * File names carry the Kyiv dates of the range. Formatted straight off the
 * UTC bounds, the start of a Kyiv day reads as the day before — a report asked
 * for from 1 July arrived as `internal-20260630-…`.
 */
class ReportExportController extends Controller
{
    use ParsesDateRange;

    public function internal(Request $request): BinaryFileResponse
    {
        [$from, $to] = $this->dateRange($request);

        return Excel::download(new InternalReportExport($from, $to), $this->filename('internal', $from, $to));
    }

    public function commercial(Request $request): BinaryFileResponse
    {
        [$from, $to] = $this->dateRange($request);

        return Excel::download(new CommercialReportExport($from, $to), $this->filename('commercial', $from, $to));
    }

    public function cashFlow(Request $request): BinaryFileResponse
    {
        [$from, $to] = $this->dateRange($request);

        return Excel::download(new CashFlowExport($from, $to), $this->filename('cash-flow', $from, $to));
    }

    public function counters(Request $request): BinaryFileResponse
    {
        [$from, $to] = $this->dateRange($request);

        return Excel::download(new CounterAnalyticsExport($from, $to), $this->filename('counters', $from, $to));
    }

    public function audit(Request $request): BinaryFileResponse
    {
        [$from, $to] = $this->dateRange($request);

        // Strings or nothing: an array from a mistyped URL used to reach the
        // typed constructor raw — a TypeError and a 500. The user id
        // must also be digits: 'abc' against the bigint column is a Postgres
        // 22P02, the same 500 one layer lower (fifth pass).
        $eventType = $request->input('event_type');
        $userId = $request->input('user_id');

        return Excel::download(
            new AuditLogExport(
                $from,
                $to,
                is_string($eventType) ? $eventType : null,
                is_scalar($userId) && ctype_digit((string) $userId) ? (int) $userId : null,
            ),
            $this->filename('audit', $from, $to),
        );
    }

    private function filename(string $report, Carbon $from, Carbon $to): string
    {
        [$fromLabel, $toLabel] = $this->dateRangeLabel($from, $to);

        return "{$report}-{$fromLabel}-{$toLabel}.xlsx";
    }
}
