<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'adjustment' to reading_type enum (PostgreSQL)
        DB::statement("ALTER TABLE shift_counter_readings DROP CONSTRAINT IF EXISTS shift_counter_readings_reading_type_check");
        DB::statement("ALTER TABLE shift_counter_readings ADD CONSTRAINT shift_counter_readings_reading_type_check CHECK (reading_type IN ('morning', 'evening', 'adjustment'))");

        // Drop unique constraint that prevents adjustment readings
        // (adjustments don't follow the one-per-type-per-shift rule)
        DB::statement("ALTER TABLE shift_counter_readings DROP CONSTRAINT IF EXISTS unique_shift_equipment_reading");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE shift_counter_readings DROP CONSTRAINT IF EXISTS shift_counter_readings_reading_type_check");
        DB::statement("ALTER TABLE shift_counter_readings ADD CONSTRAINT shift_counter_readings_reading_type_check CHECK (reading_type IN ('morning', 'evening'))");

        // Re-add unique constraint (ignoring adjustment rows)
        DB::statement("CREATE UNIQUE INDEX unique_shift_equipment_reading ON shift_counter_readings (shift_id, equipment_id, reading_type) WHERE reading_type IN ('morning', 'evening')");
    }
};
