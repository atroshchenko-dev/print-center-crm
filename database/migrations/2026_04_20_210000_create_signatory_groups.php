<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Signatory Groups table ──────────────────────
        Schema::create('signatory_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');               // e.g. "Група 1–10 (повний доступ)"
            $table->integer('daily_limit')->nullable(); // global daily limit for the group (null = unlimited)
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ─── Group ↔ ServiceCategory pivot ────────────────
        Schema::create('signatory_group_service_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signatory_group_id')
                ->constrained('signatory_groups')
                ->cascadeOnDelete();
            $table->foreignId('service_category_id')
                ->constrained('service_categories')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['signatory_group_id', 'service_category_id'], 'sg_cat_unique');
        });

        // ─── Add group_id to university_refs ─────────────
        Schema::table('university_refs', function (Blueprint $table) {
            $table->foreignId('signatory_group_id')
                ->nullable()
                ->after('is_active')
                ->constrained('signatory_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('university_refs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signatory_group_id');
        });

        Schema::dropIfExists('signatory_group_service_category');
        Schema::dropIfExists('signatory_groups');
    }
};
