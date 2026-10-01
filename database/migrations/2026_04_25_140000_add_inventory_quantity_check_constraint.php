<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add CHECK constraint to prevent negative stock.
     * This is a safety net at the database level — InventoryService
     * already clamps at zero in PHP, but this prevents bypasses
     * via raw SQL, tinker, or future code changes.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT chk_quantity_non_negative CHECK (current_quantity >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT IF EXISTS chk_quantity_non_negative');
    }
};
