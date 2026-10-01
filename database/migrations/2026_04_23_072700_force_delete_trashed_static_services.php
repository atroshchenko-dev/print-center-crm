<?php

declare(strict_types=1);

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Permanently delete all soft-deleted services.
 *
 * These are legacy static services that were replaced by the Constructor.
 * order_items.service_id has no FK constraint, so historical orders are safe.
 * service_parameter_groups cascade on delete, so they clean up automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        $trashed = Service::onlyTrashed()->get();

        Log::info("[Migration] Force-deleting {$trashed->count()} trashed services", [
            'ids' => $trashed->pluck('id')->toArray(),
            'names' => $trashed->pluck('name')->toArray(),
        ]);

        Service::onlyTrashed()->forceDelete();
    }

    /**
     * Refuses instead of pretending (audit finding M-5).
     *
     * up() force-deleted rows; nothing in the database can bring them back. An
     * empty down() would let `migrate:rollback` report success and leave the
     * operator believing the legacy services were restored. Failing loudly is
     * the only honest answer — restoring them means a database backup.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'This migration force-deleted rows and cannot be reversed. '
            . 'Restore the services from a database backup instead.'
        );
    }
};
