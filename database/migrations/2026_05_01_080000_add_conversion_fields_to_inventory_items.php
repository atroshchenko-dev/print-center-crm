<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add paper conversion mapping fields to inventory_items.
 *
 * Allows mapping A4 items to their A3 source:
 *   "Папір А4 80 г/м²" convertible_from → "Папір А3 80 г/м²", ratio = 2.0
 *
 * Used by InventoryService::deductStock() for automatic A3→A4 conversion
 * when A4 stock is insufficient.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->foreignId('convertible_from_id')
                ->nullable()
                ->after('min_quantity')
                ->constrained('inventory_items')
                ->nullOnDelete();

            $table->decimal('conversion_ratio', 8, 2)
                ->default(0)
                ->after('convertible_from_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropForeign(['convertible_from_id']);
            $table->dropColumn(['convertible_from_id', 'conversion_ratio']);
        });
    }
};
