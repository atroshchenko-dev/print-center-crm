<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatory_cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_ref_id')
                ->constrained('university_refs')
                ->cascadeOnDelete();
            $table->foreignId('department_id')
                ->constrained('departments')
                ->cascadeOnDelete();
            // Скільки замовлень утворили пару. 0 — рядок, доданий руками
            // в адмінці «наперед», а не накопичений з історії.
            $table->unsignedInteger('orders_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // Пара існує в одному екземплярі; цей же індекс обслуговує
            // читання «центри витрат такого-то підписанта» лівим префіксом.
            $table->unique(['university_ref_id', 'department_id'], 'scc_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatory_cost_centers');
    }
};
