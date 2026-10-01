<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One morning reading per machine per shift — the rule that was deleted rather
 * than narrowed.
 *
 * 2026_04_15_000003 introduced the `adjustment` reading type, and an adjustment
 * is deliberately repeatable: a machine can be corrected twice in one shift. The
 * constraint from the original table covered all three types, so it had to go.
 * What should have taken its place is spelled out in that migration's own
 * down() — the same index, narrowed to the types that really are once per shift.
 * up() dropped and never rebuilt, so since April nothing at any level has
 * stopped a second morning reading for the same machine in the same shift.
 *
 * The table is append-only and a trigger enforces it, so duplicates already in
 * place cannot be tidied up here. This names them and stops instead: which of
 * two immutable readings is the real one is not a question a migration is
 * entitled to answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('shift_counter_readings')
            ->select('shift_id', 'equipment_id', 'reading_type', DB::raw('COUNT(*) AS n'))
            ->whereIn('reading_type', ['morning', 'evening'])
            ->groupBy('shift_id', 'equipment_id', 'reading_type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $rows = $duplicates
                ->map(fn ($d) => "shift {$d->shift_id}/equipment {$d->equipment_id}/{$d->reading_type} ×{$d->n}")
                ->implode('; ');

            throw new \RuntimeException(
                'Duplicate counter readings stand in the way of the unique index, and the table is '
                . 'append-only — they have to be settled by hand: ' . $rows,
            );
        }

        // Anything already holding the name is the April index in its old,
        // all-three-types shape, or a re-run of this migration — both want
        // replacing. If the name belongs to a constraint instead, PostgreSQL
        // refuses and says which one, which is the right way to find out.
        DB::statement('DROP INDEX IF EXISTS unique_shift_equipment_reading');

        DB::statement("
            CREATE UNIQUE INDEX unique_shift_equipment_reading
            ON shift_counter_readings (shift_id, equipment_id, reading_type)
            WHERE reading_type IN ('morning', 'evening')
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unique_shift_equipment_reading');
    }
};
