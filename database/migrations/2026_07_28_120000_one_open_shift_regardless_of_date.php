<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * At most one open shift overall — not one per calendar date.
 *
 * A shift opened in the evening stays open past midnight until the 03:00
 * auto-close (TZ §4.4). The old index only stopped two open shifts sharing a
 * date, so between midnight and 03:00 a second shift could legitimately be
 * opened on top of a running one. The application guard had the same blind
 * spot; both are now keyed on status alone.
 *
 * Any such pair already in the table is closed here the same way the 03:00 job
 * would have closed it, so it enters the normal morning settlement queue
 * instead of blocking the index. Cash figures are left untouched —
 * reconcileCash() recomputes them when the operator settles.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $open = DB::table('shifts')
                ->where('status', 'open')
                ->whereNull('deleted_at')
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->pluck('id');

            // Keep the most recent; the rest are leftovers from the midnight gap.
            $stale = $open->slice(1);

            if ($stale->isNotEmpty()) {
                DB::table('shifts')->whereIn('id', $stale)->update([
                    'status'              => 'auto_closed',
                    'auto_closed'         => true,
                    'settlement_required' => true,
                    'closed_at'           => now(),
                ]);

                Log::warning('Migration closed shifts left open by the midnight gap', [
                    'shift_ids' => $stale->values()->all(),
                ]);
            }

            DB::statement('DROP INDEX IF EXISTS unique_open_shift_per_date');
            DB::statement("
                CREATE UNIQUE INDEX unique_open_shift
                ON shifts (status)
                WHERE status = 'open' AND deleted_at IS NULL
            ");
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unique_open_shift');
        DB::statement('
            CREATE UNIQUE INDEX unique_open_shift_per_date
            ON shifts (date)
            WHERE status = \'open\' AND deleted_at IS NULL
        ');
    }
};
