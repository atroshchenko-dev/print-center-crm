<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('unit')->default('шт'); // аркуш, шт, пружина, мл
            $table->decimal('current_quantity', 12, 4)->default(0);
            $table->decimal('avg_cost', 12, 4)->default(0);
            $table->decimal('min_quantity', 12, 4)->default(0); // alert threshold
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
