<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add PostgreSQL-level immutability triggers for append-only tables.
 *
 * Defense-in-depth: even if Eloquent guards are bypassed (raw SQL,
 * DB console, migration scripts), these triggers prevent modification
 * of critical financial and audit records at the database level.
 *
 * Covers: ledger_transactions, audit_logs, inventory_movements.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Reusable trigger function: raises exception on UPDATE or DELETE
        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_modification()
            RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'Modification of append-only records is forbidden: % on %', TG_OP, TG_TABLE_NAME;
                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        // ledger_transactions: append-only
        DB::unprepared("
            DROP TRIGGER IF EXISTS trg_ledger_immutable ON ledger_transactions;
            CREATE TRIGGER trg_ledger_immutable
                BEFORE UPDATE OR DELETE ON ledger_transactions
                FOR EACH ROW EXECUTE FUNCTION prevent_modification();
        ");

        // audit_logs: append-only
        DB::unprepared("
            DROP TRIGGER IF EXISTS trg_audit_immutable ON audit_logs;
            CREATE TRIGGER trg_audit_immutable
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION prevent_modification();
        ");

        // inventory_movements: append-only
        DB::unprepared("
            DROP TRIGGER IF EXISTS trg_movement_immutable ON inventory_movements;
            CREATE TRIGGER trg_movement_immutable
                BEFORE UPDATE OR DELETE ON inventory_movements
                FOR EACH ROW EXECUTE FUNCTION prevent_modification();
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ledger_immutable ON ledger_transactions;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_immutable ON audit_logs;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_movement_immutable ON inventory_movements;');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_modification();');
    }
};
