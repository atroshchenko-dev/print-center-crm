<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Move material_description from orders to order_items (per-item)
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('material_description', 500)->nullable()->after('service_name');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('material_description');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('material_description', 500)->nullable()->after('cost_center');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('material_description');
        });
    }
};
