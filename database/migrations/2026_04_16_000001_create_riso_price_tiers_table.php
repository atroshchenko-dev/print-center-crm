<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riso_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->integer('min_qty');
            $table->integer('max_qty')->nullable();  // null = unlimited (500+)
            $table->decimal('cost_per_copy', 8, 4);  // ціна за 1 прогін А3
            $table->timestamps();

            $table->index(['min_qty', 'max_qty']);
        });

        // Seed default tiers from the user's pricing table
        DB::table('riso_price_tiers')->insert([
            ['min_qty' => 50,  'max_qty' => 99,   'cost_per_copy' => 0.1100, 'created_at' => now(), 'updated_at' => now()],
            ['min_qty' => 100, 'max_qty' => 149,  'cost_per_copy' => 0.0600, 'created_at' => now(), 'updated_at' => now()],
            ['min_qty' => 150, 'max_qty' => 199,  'cost_per_copy' => 0.0400, 'created_at' => now(), 'updated_at' => now()],
            ['min_qty' => 200, 'max_qty' => 249,  'cost_per_copy' => 0.0300, 'created_at' => now(), 'updated_at' => now()],
            ['min_qty' => 250, 'max_qty' => 299,  'cost_per_copy' => 0.0250, 'created_at' => now(), 'updated_at' => now()],
            ['min_qty' => 300, 'max_qty' => 349,  'cost_per_copy' => 0.0250, 'created_at' => now(), 'updated_at' => now()],
            ['min_qty' => 350, 'max_qty' => 399,  'cost_per_copy' => 0.0220, 'created_at' => now(), 'updated_at' => now()],
            ['min_qty' => 400, 'max_qty' => 449,  'cost_per_copy' => 0.0210, 'created_at' => now(), 'updated_at' => now()],
            ['min_qty' => 450, 'max_qty' => 499,  'cost_per_copy' => 0.0200, 'created_at' => now(), 'updated_at' => now()],
            ['min_qty' => 500, 'max_qty' => null,  'cost_per_copy' => 0.0190, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('riso_price_tiers');
    }
};
