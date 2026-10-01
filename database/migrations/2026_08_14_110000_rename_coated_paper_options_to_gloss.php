<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the print-constructor options that say "(крейдований)" to
 * "(крейдований, глянець)".
 *
 * The option label is the identity the seeder matches on, so without this the
 * seeder would not update the existing rows — it would create a second set
 * beside them and leave the operator picking from two "крейдований" tiles,
 * one of which no longer resolves to anything sold.
 *
 * The label is repeated down the cascade: the sidedness and fill options carry
 * the paper label inside their own names ("А4: Папір 250 г/м² (крейдований):
 * 4+0: до 25%"), which is why this is a substring replace across the whole
 * table rather than a rename of six rows.
 *
 * Business-card labels are spelled "Крейдований 250 г/м² (глянець)" and never
 * contain "г/м² (крейдований)", so they are untouched. Past orders keep the old
 * wording: their option names are frozen in the order_items.service_snapshot
 * JSONB, which nothing here rewrites.
 */
return new class extends Migration
{
    private const OLD = 'г/м² (крейдований)';

    private const NEW = 'г/м² (крейдований, глянець)';

    public function up(): void
    {
        // Idempotent: the replacement contains a comma where the pattern has a
        // closing paren, so a second run finds nothing left to match.
        $this->replace(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->replace(self::NEW, self::OLD);
    }

    private function replace(string $from, string $to): void
    {
        DB::statement(
            'UPDATE service_parameter_options
                SET name = REPLACE(name, ?, ?), updated_at = NOW()
              WHERE name LIKE ?',
            [$from, $to, '%'.$from.'%']
        );
    }
};
