<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index()->comment('Calendar date of the shift (one shift per day)');
            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->enum('status', ['open', 'closed', 'auto_closed'])->default('open');

            // Cash register snapshot
            $table->decimal('cash_start', 10, 2)->default(0)->comment('Starting cash balance (carried from previous shift actual)');
            $table->decimal('cash_calculated', 10, 2)->nullable()->comment('System-calculated expected balance at close');
            $table->decimal('cash_actual', 10, 2)->nullable()->comment('Actual counted cash at close');
            $table->text('cash_discrepancy_reason')->nullable()->comment('Required when actual != calculated');

            $table->boolean('auto_closed')->default(false)->comment('True if closed by scheduler at 03:00');
            $table->boolean('settlement_required')->default(false)->comment('True if auto_closed — requires morning settlement');
            $table->boolean('settlement_done')->default(false);

            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
