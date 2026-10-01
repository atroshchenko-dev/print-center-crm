<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Dynamic numbering: INT-2605-001 / COM-2605-023
            $table->string('order_number', 20)->unique();
            $table->string('order_prefix', 3); // INT or COM
            $table->unsignedSmallInteger('order_year');  // e.g. 26
            $table->unsignedSmallInteger('order_month'); // e.g. 05
            $table->unsignedInteger('order_sequence');   // resets monthly

            // Order type
            $table->string('type', 20); // 'internal' or 'commercial'

            // Status with enum-like constraint
            $table->string('status', 30)->default('new');
            // Possible: new, in_progress, ready, paid_issued, completed_issued, cancelled

            // Optimistic Locking
            $table->unsignedInteger('version')->default(1);

            // Relationships
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('shift_id')
                ->nullable()
                ->constrained('shifts')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Internal order fields
            $table->string('authorized_person')->nullable(); // Signatory name
            $table->string('cost_center')->nullable();       // Department or project
            $table->boolean('limit_exceeded')->default(false);

            // Commercial order fields
            $table->string('payment_method', 20)->nullable(); // 'cash' or 'card'

            // Totals (calculated, cached for performance)
            $table->decimal('total_commercial', 12, 2)->default(0);
            $table->decimal('total_cost', 12, 2)->default(0);

            // Cancellation
            $table->text('cancellation_reason')->nullable();
            $table->boolean('is_technical_defect')->default(false);

            // Timestamps (UTC)
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['type', 'status']);
            $table->index(['order_year', 'order_month']);
            $table->index('shift_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
