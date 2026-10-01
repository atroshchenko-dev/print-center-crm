<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            
            $table->string('type'); // in, out, adjustment, auto_deduct
            $table->decimal('quantity', 12, 4); // positive for in, negative for out
            $table->decimal('unit_cost', 12, 4)->default(0); 
            $table->decimal('total_cost', 12, 4)->default(0);
            
            $table->nullableMorphs('reference'); // e.g. App\Models\Order 123
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();

            $table->timestamp('created_at'); // Append-only, no updated_at softDeletes
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
