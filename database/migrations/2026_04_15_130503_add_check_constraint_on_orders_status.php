<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add CHECK constraint on orders.status to enforce valid values at DB level.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT chk_orders_status
            CHECK (status IN ('new','in_progress','ready','paid_issued','completed_issued','cancelled'))
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE orders DROP CONSTRAINT IF EXISTS chk_orders_status");
    }
};
