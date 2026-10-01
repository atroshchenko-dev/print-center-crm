<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Seed Manager users with default permissions (missed in original migration)
        DB::table('users')
            ->where('role', 'manager')
            ->whereRaw("permissions = '[]'::jsonb")
            ->update([
                'permissions' => json_encode(['orders', 'ledger', 'reports']),
            ]);

        // Also ensure Executor users have defaults if missed
        DB::table('users')
            ->where('role', 'executor')
            ->whereRaw("permissions = '[]'::jsonb")
            ->update([
                'permissions' => json_encode(['orders', 'ledger']),
            ]);

        // Ensure Admin users have all permissions
        DB::table('users')
            ->where('role', 'admin')
            ->whereRaw("permissions = '[]'::jsonb")
            ->update([
                'permissions' => json_encode([
                    'orders', 'ledger', 'reports', 'services',
                    'equipment', 'inventory', 'university', 'users',
                ]),
            ]);
    }

    /**
     * Intentionally a no-op, and correct as such rather than a gap.
     *
     * The migration only filled in permissions that were an empty array. It
     * kept no record of which rows it touched, and by now an admin may have
     * edited any of them by hand. Clearing permissions to "undo" would lock
     * real users out of modules they legitimately have; leaving them is at
     * worst redundant. There is nothing safe to reverse.
     */
    public function down(): void
    {
        //
    }
};
