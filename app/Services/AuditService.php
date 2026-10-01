<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

/**
 * AuditService
 *
 * Provides a clean interface for writing immutable audit log entries.
 * Wraps AuditLog::record() with dependency injection support.
 *
 * The event types are catalogued in App\Enums\AuditEventType — the audit screen
 * builds its filter from it, and AuditEventCatalogueTest fails if a call site
 * writes one that is not listed there. The list that used to sit in this
 * docblock said `counter_adjusted`, which no line of this project has ever
 * written; the same wrong slug had been copied into the screen's filter.
 */
class AuditService
{
    public function log(
        string $eventType,
        User $user,
        string $description,
        ?int $shiftId = null,
        array $meta = [],
    ): AuditLog {
        return AuditLog::record(
            eventType:   $eventType,
            user:        $user,
            description: $description,
            shiftId:     $shiftId,
            meta:        $meta,
        );
    }
}
