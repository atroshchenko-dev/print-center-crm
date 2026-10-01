<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create settings table — key-value store for system configuration.
 *
 * Replaces file-based storage/app/settings.json with DB persistence.
 * Cached via Redis for zero-overhead reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value');
            $table->timestamp('updated_at')->useCurrent();
        });

        // Seed default values
        DB::table('settings')->insert([
            ['key' => 'cache_enabled', 'value' => 'true', 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
