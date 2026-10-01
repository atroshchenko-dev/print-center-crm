<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_center_initiators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')
                ->constrained('departments')
                ->cascadeOnDelete();
            // Те написання, яким людину назвали вперше — воно й показується.
            $table->string('name');
            // LOWER(TRIM(name)) — за ним шукається й унікалізується. Окрема
            // колонка, а не функціональний індекс: ключ тут складений, і явна
            // колонка тримає нормалізацію в коді, а не в схемі.
            $table->string('name_key');
            $table->unsignedInteger('orders_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['department_id', 'name_key'], 'cci_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_center_initiators');
    }
};
