<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ReportExportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role'        => UserRole::Admin,
            'permissions' => User::permissionKeys(),
        ]);
    }

    /**
     * The name the requested range spells out, written down rather than
     * recomputed. This used to rebuild the controller's own conversion, so it
     * agreed with the off-by-one it was supposed to catch: a range starting on
     * 1 January was asserted — and delivered — as `-20251231-`.
     */
    private function expectedFilename(string $prefix, string $fromDate, string $toDate): string
    {
        return $prefix
            .'-'.Carbon::parse($fromDate)->format('Ymd')
            .'-'.Carbon::parse($toDate)->format('Ymd')
            .'.xlsx';
    }

    public function test_exports_internal_report_as_xlsx(): void
    {
        Excel::fake();

        $response = $this->actingAs($this->admin)
            ->get(route('reports.internal.export', ['from' => '2026-01-01', 'to' => '2026-01-31']));

        $response->assertOk();
        Excel::assertDownloaded($this->expectedFilename('internal', '2026-01-01', '2026-01-31'));
    }

    public function test_exports_commercial_report_as_xlsx(): void
    {
        Excel::fake();

        $response = $this->actingAs($this->admin)
            ->get(route('reports.commercial.export', ['from' => '2026-01-01', 'to' => '2026-01-31']));

        $response->assertOk();
        Excel::assertDownloaded($this->expectedFilename('commercial', '2026-01-01', '2026-01-31'));
    }

    public function test_exports_cash_flow_report_as_xlsx(): void
    {
        Excel::fake();

        $response = $this->actingAs($this->admin)
            ->get(route('reports.cash-flow.export', ['from' => '2026-01-01', 'to' => '2026-01-31']));

        $response->assertOk();
        Excel::assertDownloaded($this->expectedFilename('cash-flow', '2026-01-01', '2026-01-31'));
    }

    public function test_exports_counters_report_as_xlsx(): void
    {
        Excel::fake();

        $response = $this->actingAs($this->admin)
            ->get(route('reports.counters.export', ['from' => '2026-01-01', 'to' => '2026-01-31']));

        $response->assertOk();
        Excel::assertDownloaded($this->expectedFilename('counters', '2026-01-01', '2026-01-31'));
    }

    /**
     * The export's filters arrive from the query string, and an array where
     * a string is expected used to reach the typed constructor raw — a
     * TypeError, a 500, and a Telegram error alert for a mistyped URL
     *.
     */
    public function test_an_array_filter_does_not_crash_the_audit_export(): void
    {
        Excel::fake();

        $this->actingAs($this->admin)
            ->get(route('reports.audit.export', [
                'event_type' => ['x'],
                'user_id'    => ['y'],
            ]))
            ->assertOk();
    }

    /**
     * The scalar half of the same class (fifth pass): 'abc' against a
     * bigint. No Excel::fake() here on purpose — the fake short-circuits
     * before the query runs, and the 22P02 lives in the query.
     */
    public function test_a_non_numeric_user_id_does_not_crash_the_audit_export(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.audit.export', ['user_id' => 'abc']))
            ->assertOk();
    }

    public function test_exports_audit_report_as_xlsx(): void
    {
        Excel::fake();

        $response = $this->actingAs($this->admin)
            ->get(route('reports.audit.export', ['from' => '2026-01-01', 'to' => '2026-01-31']));

        $response->assertOk();
        Excel::assertDownloaded($this->expectedFilename('audit', '2026-01-01', '2026-01-31'));
    }

    public function test_requires_reports_permission(): void
    {
        $executor = User::factory()->create([
            'role'        => UserRole::Executor,
            'permissions' => ['orders', 'ledger'],
        ]);

        $response = $this->actingAs($executor)
            ->get(route('reports.internal.export'));

        $response->assertStatus(403);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get(route('reports.internal.export'));
        $response->assertRedirect(route('login'));
    }

    public function test_uses_default_date_range_when_none_provided(): void
    {
        Excel::fake();

        $response = $this->actingAs($this->admin)
            ->get(route('reports.internal.export'));

        $response->assertOk();
        // Default: this Kyiv month, up to the end of the Kyiv day.
        $expectedFrom = today('Europe/Kyiv')->startOfMonth()->format('Ymd');
        $expectedTo = today('Europe/Kyiv')->format('Ymd');
        Excel::assertDownloaded("internal-{$expectedFrom}-{$expectedTo}.xlsx");
    }

    /**
     * The name is the only place the range is written down for whoever opens
     * the file later. Built from the UTC bounds, a range starting on the 1st
     * was labelled the 30th — off by a day, every time, for any explicit range.
     */
    public function test_the_file_is_named_after_the_kyiv_dates_that_were_asked_for(): void
    {
        Excel::fake();

        $this->actingAs($this->admin)
            ->get(route('reports.internal.export', ['from' => '2026-07-01', 'to' => '2026-07-15']))
            ->assertOk();

        Excel::assertDownloaded('internal-20260701-20260715.xlsx');
    }
}
