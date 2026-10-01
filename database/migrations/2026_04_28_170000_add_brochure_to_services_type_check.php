<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE services DROP CONSTRAINT IF EXISTS services_type_check");
        DB::statement("ALTER TABLE services ADD CONSTRAINT services_type_check CHECK (type IN ('static', 'constructor', 'riso', 'brochure'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE services DROP CONSTRAINT IF EXISTS services_type_check");
        DB::statement("ALTER TABLE services ADD CONSTRAINT services_type_check CHECK (type IN ('static', 'constructor', 'riso'))");
    }
};
