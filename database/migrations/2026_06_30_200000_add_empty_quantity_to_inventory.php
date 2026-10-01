<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('empty_quantity', 12, 4)->default(0)->after('current_quantity');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('empty_quantity', 12, 4)->default(0)->after('quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn('empty_quantity');
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('empty_quantity');
        });
    }
};
