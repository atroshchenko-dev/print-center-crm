<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Services price list (static + constructor types)
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['static', 'constructor'])->default('static')
                ->comment('static = fixed price; constructor = dynamic parameter-based pricing');

            // Base pricing (for static services — full price; for constructor — base before option markups)
            $table->decimal('base_price_commercial', 10, 2)->default(0);
            $table->decimal('base_price_cost', 10, 2)->default(0);

            // Counter mapping (TZ §3.3 — for static services only; constructor uses option-level mapping)
            $table->enum('counter_type', ['bw', 'color', 'riso', 'none'])->default('none');
            $table->unsignedInteger('clicks_per_unit')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Parameter groups for constructor services
        Schema::create('service_parameter_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->string('name')->comment('e.g. "Формат", "Кольоровість"');
            $table->enum('ui_type', ['radio', 'checkbox'])
                ->default('radio')
                ->comment('radio = single choice; checkbox = multiple choice');
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Individual options within a parameter group
        Schema::create('service_parameter_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('service_parameter_groups')->cascadeOnDelete();
            $table->string('name')->comment('e.g. "A4", "4+4 (Колір, 2 сторони)"');

            // Financial modifiers (added to base price per unit)
            $table->decimal('price_markup', 10, 2)->default(0)->comment('Commercial price addition per unit');
            $table->decimal('cost_markup', 10, 2)->default(0)->comment('Cost price addition per unit');

            // Counter modifiers
            $table->enum('counter_type', ['bw', 'color', 'riso', 'none'])->default('none');
            $table->unsignedInteger('clicks_per_unit')->default(0)->comment('Clicks added to counter per 1 unit');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_parameter_options');
        Schema::dropIfExists('service_parameter_groups');
        Schema::dropIfExists('services');
    }
};
