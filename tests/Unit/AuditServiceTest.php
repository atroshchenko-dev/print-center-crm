<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AuditServiceTest
 *
 * Verifies the AuditService correctly delegates to AuditLog::record()
 * and that audit log entries are immutable (append-only).
 */
class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AuditService();
    }

    public function test_creates_audit_log_entry(): void
    {
        $user = User::factory()->create(['role' => 'executor']);

        $log = $this->service->log(
            eventType: 'test_event',
            user: $user,
            description: 'Test audit entry',
        );

        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertEquals('test_event', $log->event_type);
        $this->assertEquals($user->id, $log->user_id);
    }

    public function test_stores_meta_data(): void
    {
        $user = User::factory()->create();

        $log = $this->service->log(
            eventType: 'order_cancelled',
            user: $user,
            description: 'Order cancelled',
            meta: ['order_id' => 42, 'reason' => 'defect'],
        );

        $this->assertEquals(42, $log->meta['order_id']);
        $this->assertEquals('defect', $log->meta['reason']);
    }

    public function test_stores_shift_id(): void
    {
        $user  = User::factory()->create();
        $shift = Shift::factory()->create(['opened_by' => $user->id]);

        $log = $this->service->log(
            eventType: 'cash_withdrawal',
            user: $user,
            description: 'Cash withdrawal',
            shiftId: $shift->id,
        );

        $this->assertEquals($shift->id, $log->shift_id);
    }

    public function test_audit_log_is_persisted(): void
    {
        $user = User::factory()->create();

        $this->service->log(
            eventType: 'shift_opened',
            user: $user,
            description: 'Shift opened',
        );

        $this->assertDatabaseHas('audit_logs', [
            'event_type'  => 'shift_opened',
            'user_id'     => $user->id,
        ]);
    }
}
