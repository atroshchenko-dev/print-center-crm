<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add GIN index on order_items.service_snapshot (JSONB).
     * Required per system-rules.md — mandatory for report query performance.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS order_items_service_snapshot_gin ON order_items USING GIN (service_snapshot)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS order_items_service_snapshot_gin');
        }
    }
};
