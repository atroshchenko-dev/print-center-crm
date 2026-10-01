<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit log table.
     * No UPDATE, no DELETE, no softDeletes — same principle as ledger_transactions.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type')->index()->comment(
                'e.g. order_cancelled, cash_withdrawal, counter_adjusted, limit_exceeded, shift_auto_closed'
            );
            $table->foreignId('user_id')->constrained('users')->comment('Who triggered the event');
            $table->foreignId('shift_id')->nullable()->constrained('shifts');
            $table->text('description')->comment('Human-readable description');
            $table->jsonb('meta')->nullable()->comment('Additional structured data (order_id, old_value, new_value, etc.)');
            $table->timestamp('created_at')->useCurrent();
            // NO updated_at, NO softDeletes — strictly append-only

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
