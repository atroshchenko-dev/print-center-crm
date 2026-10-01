<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add missing FK constraint on ledger_transactions.order_id.
 *
 * shift_id and user_id already have FK constraints, but order_id was missing.
 * Uses SET NULL on delete since ledger transactions are append-only and
 * should survive order soft-deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_transactions', function (Blueprint $table) {
            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_transactions', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });
    }
};
