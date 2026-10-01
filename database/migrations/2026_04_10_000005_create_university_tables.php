<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Authorized persons who can sign internal orders
        Schema::create('university_refs', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('position')->nullable()->comment('Job title / role');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Cost centers: departments and projects
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['department', 'project'])->default('department');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Monthly printing limits per department
        Schema::create('department_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments');
            $table->string('limit_type')->default('bw_copies')->comment('e.g. bw_copies — type of limit');
            $table->unsignedInteger('monthly_limit')->default(0)->comment('Max allowed per month');
            $table->unsignedInteger('current_usage')->default(0)->comment('Used this month');
            $table->timestamp('reset_at')->nullable()->comment('Next reset date (1st of month, 00:01 Kyiv)');
            $table->timestamps();

            $table->unique(['department_id', 'limit_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_limits');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('university_refs');
    }
};
