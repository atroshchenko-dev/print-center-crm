<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ink_coverage_analyses', function (Blueprint $table) {
            $table->id();

            // Original file info
            $table->string('filename');
            $table->string('disk_path');

            // Analysis results
            $table->unsignedInteger('total_pages')->default(0);
            $table->jsonb('pages_detail')->default('[]');
            $table->jsonb('summary')->default('[]');
            $table->decimal('grand_total', 12, 2)->default(0);

            // Manual override
            $table->boolean('force_bw')->default(false);

            // Processing status: pending | processing | completed | failed
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();

            // Split PDF paths
            $table->string('bw_split_path')->nullable();
            $table->string('color_split_path')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ink_coverage_analyses');
    }
};
