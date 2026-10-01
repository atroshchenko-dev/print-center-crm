<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add composite indexes for frequently used queries.
 *
 * - orders(order_prefix, order_year, order_month) — used by OrderNumberService::generate()
 * - ledger_transactions(shift_id, type) — used by Shift::calculateExpectedBalance()
 * - shift_counter_readings(equipment_id, reading_type, created_at) — used by ShiftService::validateMorningReading()
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['order_prefix', 'order_year', 'order_month'], 'idx_orders_prefix_year_month');
        });

        Schema::table('ledger_transactions', function (Blueprint $table) {
            $table->index(['shift_id', 'type'], 'idx_ledger_shift_type');
        });

        Schema::table('shift_counter_readings', function (Blueprint $table) {
            $table->index(
                ['equipment_id', 'reading_type', 'created_at'],
                'idx_scr_equipment_reading_created',
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_prefix_year_month');
        });

        Schema::table('ledger_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_ledger_shift_type');
        });

        Schema::table('shift_counter_readings', function (Blueprint $table) {
            $table->dropIndex('idx_scr_equipment_reading_created');
        });
    }
};
