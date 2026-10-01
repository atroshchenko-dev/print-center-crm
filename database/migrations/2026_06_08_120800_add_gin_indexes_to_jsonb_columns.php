<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS audit_logs_meta_gin ON audit_logs USING GIN (meta)');
            DB::statement('CREATE INDEX IF NOT EXISTS service_parameter_groups_depends_on_gin ON service_parameter_groups USING GIN (depends_on)');
            DB::statement('CREATE INDEX IF NOT EXISTS service_parameter_options_depends_on_gin ON service_parameter_options USING GIN (depends_on)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS audit_logs_meta_gin');
            DB::statement('DROP INDEX IF EXISTS service_parameter_groups_depends_on_gin');
            DB::statement('DROP INDEX IF EXISTS service_parameter_options_depends_on_gin');
        }
    }
};
