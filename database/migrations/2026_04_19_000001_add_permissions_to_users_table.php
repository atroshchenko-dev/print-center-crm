<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('permissions')->default('[]')->after('is_active');
        });

        // Admin users get all permissions by default
        DB::table('users')
            ->where('role', 'admin')
            ->update([
                'permissions' => json_encode([
                    'orders', 'ledger', 'reports', 'services',
                    'equipment', 'inventory', 'university', 'users',
                ]),
            ]);

        // Executor users get workspace permissions by default
        DB::table('users')
            ->where('role', 'executor')
            ->update([
                'permissions' => json_encode(['orders', 'ledger']),
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
