<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add soft deletes to department_limits table for consistency
 * with project-wide no-physical-delete policy (ТЗ §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('department_limits', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('department_limits', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
