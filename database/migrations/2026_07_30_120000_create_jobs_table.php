<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The queue has been pointed at a table nobody created.
 *
 * `.env` carries `QUEUE_CONNECTION=database` and config/queue.php defaults to
 * the same, but this repository has a migration for `failed_jobs` and none for
 * `jobs`. Every `dispatch()` therefore raises
 * `SQLSTATE[42P01]: relation "jobs" does not exist`, which means the three
 * scheduled business jobs never even reach the queue:
 *
 *   - ActivatePendingPricesJob   (00:01)      planned price changes
 *   - AutoCloseShiftJob          (03:00)      the shift that must not stay open
 *   - ResetDepartmentLimitsJob   (1st, 00:05) monthly limits
 *
 * Reproduced on a clean database built from these migrations. Tests never saw
 * it because phpunit.xml sets QUEUE_CONNECTION=sync, so every job runs inline.
 *
 * Guarded by hasTable: the production database may already have this table from
 * a hand-run `queue:table`, and a migration that fails on deploy would roll the
 * whole release back — the schema is Laravel's own, so an existing one is
 * already the right shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobs')) {
            return;
        }

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        // Dropping this loses any work still queued, but leaving it behind would
        // make the rollback a lie about the schema. Nothing else reads it.
        Schema::dropIfExists('jobs');
    }
};
