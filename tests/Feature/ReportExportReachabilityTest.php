<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exports\AuditLogExport;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every report that can be exported has to say so on its own page.
 *
 * ReportExportController serves five workbooks. Four of them —
 * internal, commercial, cash-flow and audit — were routed, implemented,
 * tested, and linked from nowhere: `git log -S` over resources/ shows the
 * route names never appeared in a single commit. Only the counters report
 * ever grew a button. Two rounds of this audit fixed defects inside files no
 * one could download: R5-5 the internal filename, R6-1 the cash-flow day
 * boundary.
 *
 * The class of it is R3-18 — an endpoint that exists, validates, and is called
 * by nothing — and the sweep that found it is the whole route table against
 * every route('...') in resources/.
 *
 * The link assertions read the page source on purpose. Vitest here mounts
 * components, not pages, and an endpoint test cannot notice that no page
 * calls it — which is precisely how this survived nine rounds.
 */
class ReportExportReachabilityTest extends TestCase
{
    use RefreshDatabase;

    /** Every page ReportExportController serves a workbook for. */
    private const REPORT_PAGES = [
        'Internal.vue'   => 'reports.internal.export',
        'Commercial.vue' => 'reports.commercial.export',
        'CashFlow.vue'   => 'reports.cash-flow.export',
        'Counters.vue'   => 'reports.counters.export',
        'Audit.vue'      => 'reports.audit.export',
    ];

    public function test_each_report_page_links_to_its_own_export(): void
    {
        foreach (self::REPORT_PAGES as $page => $routeName) {
            $source = (string) file_get_contents(resource_path("js/Pages/Reports/{$page}"));

            $this->assertTrue(
                str_contains($source, "route('{$routeName}'"),
                "{$page} offers no way to download {$routeName}.",
            );
        }
    }

    public function test_each_export_link_carries_the_filters_its_page_applies(): void
    {
        // A download that ignores the filters above it is the defect this round
        // fixed on the reconciliation screen. The audit page filters by four
        // things, the other three by two.
        $audit = (string) file_get_contents(resource_path('js/Pages/Reports/Audit.vue'));

        foreach (['from', 'to', 'event_type', 'user_id'] as $filter) {
            $this->assertMatchesRegularExpression(
                '/reports\.audit\.export.{0,220}' . preg_quote($filter, '/') . '/s',
                $audit,
                "The audit XLSX link drops the '{$filter}' filter.",
            );
        }
    }

    /**
     * The event filter has to come from the server, because the copy that lived
     * in the page drifted for three rounds without anything failing: thirteen
     * event types the system writes were not offered at all, and one that was
     * offered — `counter_adjusted` — is written by nothing, so choosing it
     * filtered the journal down to nothing every time. Round 13 added
     * `approval_email_failed` *so that* an undelivered signature request would
     * be visible; the filter it needed did not exist.
     */
    public function test_the_audit_screen_gets_its_event_filter_from_the_server(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('reports.audit'));
        $response->assertOk();

        $values = array_column($response->viewData('page')['props']['eventTypes'], 'value');

        $this->assertContains('approval_email_failed', $values);
        $this->assertContains('counter_adjustment', $values);
        $this->assertNotContains('counter_adjusted', $values, 'No call site has ever written this');
    }

    public function test_the_audit_page_keeps_no_list_of_event_types_of_its_own(): void
    {
        $source = (string) file_get_contents(resource_path('js/Pages/Reports/Audit.vue'));

        $this->assertStringNotContainsString(
            "'order_cancelled'",
            $source,
            'A second list of event types in the page is what drifted last time.',
        );
    }

    public function test_the_audit_export_honours_the_user_filter(): void
    {
        $admin  = User::factory()->admin()->create(['name' => 'Адмін']);
        $other  = User::factory()->create(['name' => 'Оператор', 'role' => 'executor']);

        $this->logEvent($admin, 'shift_opened');
        $this->logEvent($other, 'cash_withdrawal');

        $export = new AuditLogExport(
            Carbon::now()->subDay(),
            Carbon::now()->addDay(),
            null,
            $other->id,
        );

        $rows = $export->collection();

        $this->assertCount(1, $rows, 'The screen filters by user; the workbook must too.');
        $this->assertSame($other->id, $rows->first()->user_id);
    }

    public function test_the_audit_export_still_honours_the_event_type_filter(): void
    {
        $admin = User::factory()->admin()->create();

        $this->logEvent($admin, 'shift_opened');
        $this->logEvent($admin, 'cash_withdrawal');

        $rows = (new AuditLogExport(
            Carbon::now()->subDay(),
            Carbon::now()->addDay(),
            'cash_withdrawal',
        ))->collection();

        $this->assertCount(1, $rows);
        $this->assertSame('cash_withdrawal', $rows->first()->event_type);
    }

    public function test_the_audit_route_passes_the_user_filter_to_the_workbook(): void
    {
        $admin = User::factory()->admin()->create();
        $this->logEvent($admin, 'shift_opened');

        $this->actingAs($admin)
            ->get(route('reports.audit.export', ['user_id' => $admin->id]))
            ->assertOk();
    }

    private function logEvent(User $user, string $eventType): AuditLog
    {
        return AuditLog::create([
            'event_type'  => $eventType,
            'user_id'     => $user->id,
            'description' => "test {$eventType}",
            'created_at'  => Carbon::now(),
        ]);
    }
}
