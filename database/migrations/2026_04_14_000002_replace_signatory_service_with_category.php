<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop old service-level pivot (replaced by category-level)
        Schema::dropIfExists('signatory_service');

        Schema::create('signatory_service_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_ref_id')
                ->constrained('university_refs')
                ->cascadeOnDelete();
            $table->foreignId('service_category_id')
                ->constrained('service_categories')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['university_ref_id', 'service_category_id'], 'sign_cat_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatory_service_category');

        // Restore old pivot
        Schema::create('signatory_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_ref_id')->constrained('university_refs')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['university_ref_id', 'service_id']);
        });
    }
};
