<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ShiftStatus;
use App\Models\AuditLog;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ShiftCloseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftCloseServiceTest extends TestCase
{
    use RefreshDatabase;

    private ShiftCloseService $closeService;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->closeService = app(ShiftCloseService::class);
        $this->user = User::factory()->create(['role' => 'admin']);
    }

    // ─── closeShift() ───────────────────────────────────

    public function test_close_shift_sets_closed_status_and_metadata(): void
    {
        $shift = Shift::factory()->create([
            'status'    => ShiftStatus::Open->value,
            'opened_by' => $this->user->id,
        ]);

        $result = $this->closeService->closeShift($shift, $this->user);

        $this->assertEquals(ShiftStatus::Closed, $result->status);
        $this->assertEquals($this->user->id, $result->closed_by);
        $this->assertNotNull($result->closed_at);
    }

    public function test_close_shift_throws_if_not_open(): void
    {
        $shift = Shift::factory()->create([
            'status'    => ShiftStatus::Closed->value,
            'opened_by' => $this->user->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not open');

        $this->closeService->closeShift($shift, $this->user);
    }

    public function test_close_shift_creates_audit_log(): void
    {
        $shift = Shift::factory()->create([
            'status'    => ShiftStatus::Open->value,
            'opened_by' => $this->user->id,
        ]);

        $this->closeService->closeShift($shift, $this->user);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'shift_closed',
            'user_id'    => $this->user->id,
            'shift_id'   => $shift->id,
        ]);
    }

    // ─── autoCloseShift() ───────────────────────────────

    public function test_auto_close_sets_correct_flags(): void
    {
        $shift = Shift::factory()->create([
            'status'    => ShiftStatus::Open->value,
            'opened_by' => $this->user->id,
        ]);

        $result = $this->closeService->autoCloseShift($shift);

        $this->assertEquals(ShiftStatus::AutoClosed, $result->status);
        $this->assertTrue($result->auto_closed);
        $this->assertTrue($result->settlement_required);
        $this->assertNotNull($result->closed_at);
    }

    public function test_auto_close_skips_if_already_closed(): void
    {
        $shift = Shift::factory()->create([
            'status'    => ShiftStatus::Closed->value,
            'opened_by' => $this->user->id,
        ]);

        $result = $this->closeService->autoCloseShift($shift);

        // Should return the shift as-is, not change status
        $this->assertEquals(ShiftStatus::Closed, $result->status);
    }

    // ─── reconcileCash() ────────────────────────────────

    public function test_reconcile_cash_with_exact_match(): void
    {
        $shift = Shift::factory()->create([
            'status'     => ShiftStatus::Closed->value,
            'opened_by'  => $this->user->id,
            'cash_start' => 100.00,
        ]);

        // Expected balance = cash_start + payments (none) = 100.00
        $this->closeService->reconcileCash($shift, $this->user, 100.00, null);

        $shift->refresh();
        $this->assertEqualsWithDelta(100.00, (float) $shift->cash_actual, 0.01);
        $this->assertEqualsWithDelta(100.00, (float) $shift->cash_calculated, 0.01);
        $this->assertNull($shift->cash_discrepancy_reason);
    }

    public function test_reconcile_cash_requires_reason_for_discrepancy(): void
    {
        $shift = Shift::factory()->create([
            'status'     => ShiftStatus::Closed->value,
            'opened_by'  => $this->user->id,
            'cash_start' => 100.00,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reason is required');

        // Actual ≠ calculated, but no reason provided
        $this->closeService->reconcileCash($shift, $this->user, 95.00, null);
    }

    public function test_reconcile_cash_with_discrepancy_and_reason(): void
    {
        $shift = Shift::factory()->create([
            'status'     => ShiftStatus::Closed->value,
            'opened_by'  => $this->user->id,
            'cash_start' => 100.00,
        ]);

        $this->closeService->reconcileCash($shift, $this->user, 95.00, 'Помилка при видачі решти');

        $shift->refresh();
        $this->assertEqualsWithDelta(95.00, (float) $shift->cash_actual, 0.01);
        $this->assertEquals('Помилка при видачі решти', $shift->cash_discrepancy_reason);

        // Audit log should exist for discrepancy
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'cash_discrepancy',
            'user_id'    => $this->user->id,
            'shift_id'   => $shift->id,
        ]);
    }

    public function test_reconcile_cash_allows_tiny_discrepancy_without_reason(): void
    {
        $shift = Shift::factory()->create([
            'status'     => ShiftStatus::Closed->value,
            'opened_by'  => $this->user->id,
            'cash_start' => 100.00,
        ]);

        // 0.005 difference — less than 0.01 threshold
        $this->closeService->reconcileCash($shift, $this->user, 100.005, null);

        $shift->refresh();
        // Should succeed without exception
        $this->assertNotNull($shift->cash_actual);
    }
}
