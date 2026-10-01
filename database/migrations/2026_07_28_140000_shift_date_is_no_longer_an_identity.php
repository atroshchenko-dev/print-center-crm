<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `shifts.date` comment still promised one shift per day.
 *
 * That stopped being true with `unique_open_shift`: one shift may be open at a
 * time, on any date, so a day that starts with a morning shift and ends with
 * one running past midnight holds two rows with the same date. Every lookup
 * that ordered on `date` alone was reading a tie — including the till
 * carry-over, which took whichever row the planner handed back first.
 *
 * The queries are fixed in code; this corrects the description the next person
 * reads off the schema, since that description is what made the assumption
 * look safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("COMMENT ON COLUMN shifts.date IS 'Calendar date the shift opened on — not unique: order by (date, id) to get shifts in sequence'");
    }

    public function down(): void
    {
        DB::statement("COMMENT ON COLUMN shifts.date IS 'Calendar date of the shift (one shift per day)'");
    }
};
