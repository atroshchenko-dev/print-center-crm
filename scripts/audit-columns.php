<?php

/**
 * Every table and column of the test schema, as JSON.
 *
 * Written for the round 11 sweep and used by every round since to diff "this
 * column exists" against "something reads it" — the boundary that produced
 * R11-2, R11-3 and the `evening` vestige. Migrations say what was added; this
 * says what is there now, which is not the same list once a migration has
 * dropped something and a later one has not put it back (audit R9).
 *
 * It reads `information_schema` and writes nothing. Point it at the testing
 * database, not production: the schema is the same and the risk is not.
 *
 * Run (PowerShell, testing database — the env vars are `phpunit.xml`'s, because
 * `.env` names `crm_print`, which exists on the server and not in the network
 * this container joins):
 *   docker run --rm --network crm-t -e DB_HOST=postgres -e DB_DATABASE=crm_print_test \
 *     -e DB_USERNAME=crm -e DB_PASSWORD=secret \
 *     -v "…:/app" -w /app crm-php:8.3 \
 *     php artisan tinker --execute="require 'scripts/audit-columns.php';"
 *
 * Verified 2026-08-02, round 22. Tinker exits 255 after `--execute` even when
 * the script ran — read the JSON, not the exit code.
 *
 * Untracked in the working tree for eleven rounds, listed as `??` in every
 * `git status` a round began with. Committed in round 22 with this header
 * rather than deleted: the alternative was re-deriving the query each time it
 * was wanted.
 */

use Illuminate\Support\Facades\DB;

$rows = DB::select(<<<'SQL'
    SELECT table_name, column_name, data_type, is_nullable, column_default
    FROM information_schema.columns
    WHERE table_schema = 'public'
    ORDER BY table_name, ordinal_position
SQL);

$out = [];
foreach ($rows as $r) {
    $out[$r->table_name][] = [
        'name'     => $r->column_name,
        'type'     => $r->data_type,
        'nullable' => $r->is_nullable,
        'default'  => $r->column_default,
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
