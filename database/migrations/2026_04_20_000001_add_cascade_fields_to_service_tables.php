<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add cascade dependency + UI style fields to parameter groups
        Schema::table('service_parameter_groups', function (Blueprint $table) {
            $table->string('ui_style')->default('chips')
                ->comment('chips | tiles_large | tiles_small | dropdown')
                ->after('ui_type');

            $table->jsonb('depends_on')->nullable()
                ->comment('{"group_id": int, "option_ids": [int]} — cascade visibility condition')
                ->after('is_required');
        });

        // Add cascade dependency field to parameter options
        Schema::table('service_parameter_options', function (Blueprint $table) {
            $table->jsonb('depends_on')->nullable()
                ->comment('{"group_id": int, "option_ids": [int]} — cascade visibility condition')
                ->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('service_parameter_groups', function (Blueprint $table) {
            $table->dropColumn(['ui_style', 'depends_on']);
        });

        Schema::table('service_parameter_options', function (Blueprint $table) {
            $table->dropColumn('depends_on');
        });
    }
};
