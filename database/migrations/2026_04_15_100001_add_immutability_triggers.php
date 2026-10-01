<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-level immutability triggers for append-only tables.
 *
 * TZ §6: "UPDATE та DELETE у таблиці транзакцій суворо заборонені."
 * This enforces immutability at the PostgreSQL level, not just PHP model level.
 *
 * Affected tables:
 *   - ledger_transactions (cash register journal)
 *   - audit_logs (system audit trail)
 *   - inventory_movements (stock movement journal)
 */
return new class extends Migration
{
    private array $tables = ['ledger_transactions', 'audit_logs', 'inventory_movements'];

    public function up(): void
    {
        // Create shared trigger function
        DB::statement("
            CREATE OR REPLACE FUNCTION prevent_append_only_mutation()
            RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'UPDATE/DELETE is forbidden on append-only table: %', TG_TABLE_NAME;
                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        foreach ($this->tables as $table) {
            DB::statement("
                CREATE TRIGGER trg_{$table}_no_update
                BEFORE UPDATE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION prevent_append_only_mutation();
            ");

            DB::statement("
                CREATE TRIGGER trg_{$table}_no_delete
                BEFORE DELETE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION prevent_append_only_mutation();
            ");
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("DROP TRIGGER IF EXISTS trg_{$table}_no_update ON {$table}");
            DB::statement("DROP TRIGGER IF EXISTS trg_{$table}_no_delete ON {$table}");
        }

        DB::statement("DROP FUNCTION IF EXISTS prevent_append_only_mutation()");
    }
};
