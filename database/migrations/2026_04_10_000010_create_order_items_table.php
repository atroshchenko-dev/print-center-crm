<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Reference to the original service (nullable for soft-deleted services)
            $table->unsignedBigInteger('service_id')->nullable();

            // JSONB Snapshot — stores the full constructor configuration
            // See ТЗ Додаток А.5 for expected payload structure
            $table->jsonb('service_snapshot');

            // Denormalized fields for fast queries (also in snapshot)
            $table->string('service_name');
            $table->unsignedInteger('quantity')->default(1);

            // Price snapshot per unit
            $table->decimal('unit_price_commercial', 12, 2)->default(0);
            $table->decimal('unit_price_cost', 12, 2)->default(0);

            // Totals = unit × quantity
            $table->decimal('total_price_commercial', 12, 2)->default(0);
            $table->decimal('total_price_cost', 12, 2)->default(0);

            // Counter clicks (denormalized from snapshot)
            $table->unsignedInteger('bw_clicks')->default(0);
            $table->unsignedInteger('color_clicks')->default(0);
            $table->unsignedInteger('riso_clicks')->default(0);

            // Timestamps (UTC)
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('order_id');
            $table->index('service_id');
        });

        // GIN index on JSONB column for fast report queries (Postgres only)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX order_items_service_snapshot_gin ON order_items USING GIN (service_snapshot)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
