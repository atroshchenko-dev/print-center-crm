<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AuditEventType;
use PHPUnit\Framework\TestCase;

/**
 * The audit screen's event filter against the events the code actually writes.
 *
 * The filter list lived in `Reports/Audit.vue` as twelve literal strings and
 * drifted for three rounds without anything noticing: thirteen event types the
 * system writes were absent — `approval_email_failed`, added in round 13 so an
 * undelivered signature request would be *visible*, among them — and one entry,
 * `counter_adjusted`, matched nothing at all, so selecting "Корекція лічильника"
 * returned an empty journal while the real `counter_adjustment` rows sat one
 * slug away with no way to filter for them.
 *
 * The list now comes from App\Enums\AuditEventType. This test is what keeps the
 * enum and the call sites from drifting apart again — in both directions, since
 * an option nobody writes is exactly the defect that started this.
 */
class AuditEventCatalogueTest extends TestCase
{
    /** @return string[] Event types passed to AuditLog::record() / AuditService::log() in app/ */
    private function eventTypesWrittenByTheCode(): array
    {
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/app')
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = file_get_contents($file->getPathname());

            // The first argument, however it is written: positional, named, or
            // a ternary picking one of two types (OrderController::cancel()).
            preg_match_all(
                '/(?:AuditLog::record|auditService->log)\(\s*(?:eventType:\s*)?([^\n]*)/',
                $code,
                $calls,
            );

            foreach ($calls[1] as $argument) {
                preg_match_all("/'([a-z][a-z_]+)'/", $argument, $literals);
                $found = [...$found, ...$literals[1]];
            }
        }

        return array_values(array_unique($found));
    }

    public function test_every_event_the_code_writes_can_be_filtered_for(): void
    {
        $written   = $this->eventTypesWrittenByTheCode();
        $catalogue = array_column(AuditEventType::cases(), 'value');

        $this->assertNotEmpty($written, 'The scan itself must find call sites, or it proves nothing');

        $this->assertSame(
            [],
            array_values(array_diff($written, $catalogue)),
            'These event types reach the journal but are missing from AuditEventType, '
            . 'so the audit screen cannot filter for them.',
        );
    }

    public function test_the_filter_offers_nothing_the_code_never_writes(): void
    {
        $written   = $this->eventTypesWrittenByTheCode();
        $catalogue = array_column(AuditEventType::cases(), 'value');

        $this->assertSame(
            [],
            array_values(array_diff($catalogue, $written)),
            'These are offered as filters but no call site writes them — picking one '
            . 'can only ever return an empty journal, which is how counter_adjusted lived.',
        );
    }
}
