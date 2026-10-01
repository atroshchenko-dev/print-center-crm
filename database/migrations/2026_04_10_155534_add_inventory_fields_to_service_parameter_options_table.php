<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_parameter_options', function (Blueprint $table) {
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('inventory_qty', 10, 4)->default(0)->comment('Кількість списання матеріалу на 1 одиницю послуги');
        });
    }

    public function down(): void
    {
        Schema::table('service_parameter_options', function (Blueprint $table) {
            $table->dropForeign(['inventory_item_id']);
            $table->dropColumn(['inventory_item_id', 'inventory_qty']);
        });
    }
};
