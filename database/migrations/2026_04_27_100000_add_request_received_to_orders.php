<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add request tracking fields to orders table.
 *
 * Tracks whether the paper request (заявка) has been received for an internal order.
 * This is independent of the order status — a request can be received at any time:
 * at creation, after issuance, or in bulk at month-end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('request_received')->default(false)->after('is_reconciled');
            $table->timestamp('request_received_at')->nullable()->after('request_received');
            $table->unsignedBigInteger('request_received_by')->nullable()->after('request_received_at');

            $table->foreign('request_received_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['request_received_by']);
            $table->dropColumn(['request_received', 'request_received_at', 'request_received_by']);
        });
    }
};
