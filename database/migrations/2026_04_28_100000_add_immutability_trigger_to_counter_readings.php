<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add immutability triggers to shift_counter_readings.
 *
 * Ensures parity with ledger_transactions, audit_logs, inventory_movements.
 * Reuses the shared function: prevent_append_only_mutation() (created in 2026_04_15_100001).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TRIGGER trg_shift_counter_readings_no_update
            BEFORE UPDATE ON shift_counter_readings
            FOR EACH ROW
            EXECUTE FUNCTION prevent_append_only_mutation();
        ");

        DB::statement("
            CREATE TRIGGER trg_shift_counter_readings_no_delete
            BEFORE DELETE ON shift_counter_readings
            FOR EACH ROW
            EXECUTE FUNCTION prevent_append_only_mutation();
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_shift_counter_readings_no_update ON shift_counter_readings');
        DB::statement('DROP TRIGGER IF EXISTS trg_shift_counter_readings_no_delete ON shift_counter_readings');
    }
};
