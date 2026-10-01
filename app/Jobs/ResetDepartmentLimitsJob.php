<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DepartmentLimit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ResetDepartmentLimitsJob
 *
 * Resets all department limit counters on the 1st of each month.
 * TZ §3.1: automatic monthly reset.
 *
 * **Not scheduled, by the owner's decision of 2026-08-04 (CLOSEOUT §1.12).**
 *
 * It ran monthly for thirty-three rounds and reset nothing every time: the
 * cost-centre quota it serves has no switch anywhere in the product, so
 * `department_limits` was empty — counted on production 2026-08-04 — and this
 * `where('current_usage', '>', 0)` matched no row it could have matched
 *. The class is kept rather than deleted: it is the second half
 * of turning that quota on, and deleting it would leave the first half looking
 * sufficient.
 */
class ResetDepartmentLimitsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        $count = DepartmentLimit::where('current_usage', '>', 0)->count();

        DepartmentLimit::where('current_usage', '>', 0)->update([
            'current_usage' => 0,
            'reset_at'      => now(),
        ]);

        Log::info("ResetDepartmentLimitsJob: reset {$count} department limits for ".now()->format('F Y'));
    }
}
