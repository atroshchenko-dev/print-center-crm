<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'superseded' to order_approvals.status CHECK constraint.
 *
 * When a new approval email is sent for the same order, previous pending
 * approvals are superseded. The original enum was missing this value.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: drop old CHECK and add new one with 'superseded'
        DB::statement("ALTER TABLE order_approvals DROP CONSTRAINT IF EXISTS order_approvals_status_check");
        DB::statement("ALTER TABLE order_approvals ADD CONSTRAINT order_approvals_status_check CHECK (status::text = ANY (ARRAY['pending'::text, 'approved'::text, 'rejected'::text, 'superseded'::text]))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE order_approvals DROP CONSTRAINT IF EXISTS order_approvals_status_check");
        DB::statement("ALTER TABLE order_approvals ADD CONSTRAINT order_approvals_status_check CHECK (status::text = ANY (ARRAY['pending'::text, 'approved'::text, 'rejected'::text]))");
    }
};
