<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One live row per name in the reference books, and one active material per
 * counter type.
 *
 * Three indexes, three audit findings, one shape: a rule the code kept
 * discovering it could not rely on, because nothing stopped the state it
 * assumed away.
 *
 *  - **`departments` / `university_refs`** — `orders.cost_center` and
 *    `orders.authorized_person` hold strings, so every quota rule looks the
 *    reference up by name. With two rows under one name, only
 *    one of them owns the `DepartmentLimit`, and `where('name', …)->first()`
 *    picks whichever the database hands back. R19-1 removed the auto-save that
 *    forked rows; entering a twin by hand from the admin page stayed possible.
 *  - **`materials`** — the owner's decision of 2026-08-01 (CLOSEOUT §1.8):
 *    two active materials of one counter type must not exist at all, rather
 *    than being resolved by a tie-break nobody chose.
 *
 * **`LOWER(TRIM(…))`, not the bare column.** Production holds «Кафедра ІМЗД»
 * (live) and «кафедра ІМЗД» (deleted) — one department, one letter of case
 * apart. A plain unique index would have let that pair through, and so would
 * the `GROUP BY name` that first went looking for duplicates. PostgreSQL
 * compares strings case-sensitively, so those two are already two different
 * cost centres as far as every limit rule is concerned.
 *
 * **Partial on `deleted_at IS NULL`, and that is not a compromise.** A deleted
 * row still carries its name for the journal — production has two deleted
 * «Марунова О.О.» that a full index would reject, and deleting them to
 * please an index would erase what an order says about who signed it.
 *
 * Measured on production 2026-08-01 before writing this: zero duplicate live
 * names in either book, one active material per type. The migration therefore
 * needs no data work — but it checks anyway and says what it found, because a
 * migration that assumes is the same defect one level down.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $this->reportConflicts('departments', 'name');
            $this->reportConflicts('university_refs', 'full_name');

            DB::statement('
                CREATE UNIQUE INDEX unique_department_name
                ON departments (LOWER(TRIM(name)))
                WHERE deleted_at IS NULL
            ');

            DB::statement('
                CREATE UNIQUE INDEX unique_signatory_full_name
                ON university_refs (LOWER(TRIM(full_name)))
                WHERE deleted_at IS NULL
            ');

            DB::statement('
                CREATE UNIQUE INDEX unique_active_material_per_counter_type
                ON materials (counter_type)
                WHERE is_active = true AND deleted_at IS NULL
            ');
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unique_department_name');
        DB::statement('DROP INDEX IF EXISTS unique_signatory_full_name');
        DB::statement('DROP INDEX IF EXISTS unique_active_material_per_counter_type');
    }

    /**
     * Say out loud what would block the index, before it blocks it.
     *
     * Nothing is merged or deleted here. Merging two reference rows drags the
     * orders of both along with it, and which of the two is the real one is not
     * a question a migration gets to answer — production was measured first for
     * exactly that reason. If a row turns up here, the index will fail on the
     * next line and the log will already say which name to look at.
     */
    private function reportConflicts(string $table, string $column): void
    {
        $conflicts = DB::table($table)
            ->selectRaw("LOWER(TRIM({$column})) as name, count(*) as total")
            ->whereNull('deleted_at')
            ->groupByRaw("LOWER(TRIM({$column}))")
            ->havingRaw('count(*) > 1')
            ->pluck('total', 'name');

        if ($conflicts->isNotEmpty()) {
            Log::warning("Live duplicate names block the unique index on {$table}.{$column}", [
                'names' => $conflicts->all(),
            ]);
        }
    }
};
