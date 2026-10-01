<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Rename column in order_items (only if old name still exists)
        if (Schema::hasColumn('order_items', 'plotter_clicks')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->renameColumn('plotter_clicks', 'riso_clicks');
            });
        }

        // 2. Update equipment type check constraint for Postgres
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE equipment DROP CONSTRAINT IF EXISTS equipment_type_check;');
            DB::statement("ALTER TABLE equipment ADD CONSTRAINT equipment_type_check CHECK (type::text = ANY (ARRAY['bw'::character varying, 'color'::character varying, 'riso'::character varying]::text[]));");

            // 3. Update services counter_type check constraint for Postgres
            DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_counter_type_check;');
            DB::statement("ALTER TABLE services ADD CONSTRAINT services_counter_type_check CHECK (counter_type::text = ANY (ARRAY['bw'::character varying, 'color'::character varying, 'riso'::character varying, 'none'::character varying]::text[]));");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'riso_clicks')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->renameColumn('riso_clicks', 'plotter_clicks');
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE equipment DROP CONSTRAINT IF EXISTS equipment_type_check;');
            DB::statement("ALTER TABLE equipment ADD CONSTRAINT equipment_type_check CHECK (type::text = ANY (ARRAY['bw'::character varying, 'color'::character varying, 'plotter'::character varying]::text[]));");

            DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_counter_type_check;');
            DB::statement("ALTER TABLE services ADD CONSTRAINT services_counter_type_check CHECK (counter_type::text = ANY (ARRAY['bw'::character varying, 'color'::character varying, 'plotter'::character varying, 'none'::character varying]::text[]));");
        }
    }
};
