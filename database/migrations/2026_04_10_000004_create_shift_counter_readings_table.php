<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only table — NO softDeletes, NO updated_at
        Schema::create('shift_counter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts');
            $table->foreignId('equipment_id')->constrained('equipment');
            $table->foreignId('user_id')->constrained('users');
            $table->enum('reading_type', ['morning', 'evening']);
            $table->unsignedBigInteger('counter_value')->comment('Physical counter reading from the device');
            $table->timestamp('created_at')->useCurrent();

            // Unique constraint: one reading per type per equipment per shift
            $table->unique(['shift_id', 'equipment_id', 'reading_type'], 'unique_shift_equipment_reading');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_counter_readings');
    }
};
