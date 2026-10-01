<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();

            // Shift context
            $table->foreignId('shift_id')
                ->constrained('shifts')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Optional order reference (null for manual cash operations)
            $table->unsignedBigInteger('order_id')->nullable();

            // Transaction type
            $table->string('type', 30);
            // Types: 'shift_start', 'payment_cash', 'payment_card',
            //        'withdrawal', 'reversal', 'shift_close_actual',
            //        'shift_close_expected'

            // Payment details
            $table->string('payment_method', 20)->nullable(); // 'cash' or 'card'
            $table->decimal('amount', 12, 2); // positive = income, negative = expense
            $table->decimal('balance_after', 12, 2); // running cash balance

            // Metadata
            $table->text('comment')->nullable();

            // Who performed the operation
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // APPEND-ONLY: only created_at, NO updated_at, NO deleted_at
            // UPDATE and DELETE are STRICTLY FORBIDDEN on this table
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('shift_id');
            $table->index('order_id');
            $table->index('type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_transactions');
    }
};
