<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            // Direct mapping to CounterType enum (bw / color / riso)
            // Allows ServiceParameterOption to find the correct amortization rate.
            $table->string('counter_type', 20)->default('bw')->after('name')
                ->comment('Links to CounterType enum: bw, color, riso');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('counter_type');
        });
    }
};
