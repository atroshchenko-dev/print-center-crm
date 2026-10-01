<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add partial unique index to prevent two open shifts on the same date.
 * Application-level check exists but this provides DB-level safety against race conditions.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE UNIQUE INDEX unique_open_shift_per_date
            ON shifts (date)
            WHERE status = \'open\' AND deleted_at IS NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unique_open_shift_per_date');
    }
};
