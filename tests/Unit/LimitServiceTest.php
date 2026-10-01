<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Department;
use App\Models\DepartmentLimit;
use App\Services\LimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LimitServiceTest extends TestCase
{
    use RefreshDatabase;

    private LimitService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LimitService::class);
    }

    // ─── checkLimit ──────────────────────────────────────

    public function test_check_limit_returns_not_exceeded_when_no_department(): void
    {
        $result = $this->service->checkLimit('Невідомий відділ', 100);

        $this->assertFalse($result['exceeded']);
        $this->assertEquals(PHP_INT_MAX, $result['remaining']);
    }

    public function test_check_limit_returns_not_exceeded_when_no_limit_set(): void
    {
        Department::create(['name' => 'TestDept', 'type' => 'department', 'is_active' => true]);

        $result = $this->service->checkLimit('TestDept', 100);

        $this->assertFalse($result['exceeded']);
    }

    public function test_check_limit_returns_exceeded_when_over_quota(): void
    {
        $dept = Department::create(['name' => 'OverDept', 'type' => 'department', 'is_active' => true]);
        DepartmentLimit::create([
            'department_id' => $dept->id,
            'limit_type'    => 'bw_copies',
            'monthly_limit' => 500,
            'current_usage' => 450,
        ]);

        $result = $this->service->checkLimit('OverDept', 100);

        $this->assertTrue($result['exceeded']);
        $this->assertEquals(50, $result['remaining']);
        $this->assertEquals(500, $result['limit']);
        $this->assertEquals(450, $result['current_usage']);
    }

    public function test_check_limit_returns_not_exceeded_when_within_quota(): void
    {
        $dept = Department::create(['name' => 'OKDept', 'type' => 'department', 'is_active' => true]);
        DepartmentLimit::create([
            'department_id' => $dept->id,
            'limit_type'    => 'bw_copies',
            'monthly_limit' => 1000,
            'current_usage' => 200,
        ]);

        $result = $this->service->checkLimit('OKDept', 300);

        $this->assertFalse($result['exceeded']);
        $this->assertEquals(800, $result['remaining']);
    }

    // ─── incrementUsage ──────────────────────────────────

    public function test_increment_usage_updates_current_usage(): void
    {
        $dept = Department::create(['name' => 'IncDept', 'type' => 'department', 'is_active' => true]);
        $limit = DepartmentLimit::create([
            'department_id' => $dept->id,
            'limit_type'    => 'bw_copies',
            'monthly_limit' => 1000,
            'current_usage' => 100,
        ]);

        $this->service->incrementUsage('IncDept', 250);

        $limit->refresh();
        $this->assertEquals(350, $limit->current_usage);
    }

    public function test_increment_usage_skips_zero_clicks(): void
    {
        $dept = Department::create(['name' => 'ZeroDept', 'type' => 'department', 'is_active' => true]);
        $limit = DepartmentLimit::create([
            'department_id' => $dept->id,
            'limit_type'    => 'bw_copies',
            'monthly_limit' => 1000,
            'current_usage' => 100,
        ]);

        $this->service->incrementUsage('ZeroDept', 0);

        $limit->refresh();
        $this->assertEquals(100, $limit->current_usage); // Unchanged
    }

    // ─── returnUsage ─────────────────────────────────────

    public function test_return_usage_decrements_current_usage(): void
    {
        $dept = Department::create(['name' => 'RetDept', 'type' => 'department', 'is_active' => true]);
        $limit = DepartmentLimit::create([
            'department_id' => $dept->id,
            'limit_type'    => 'bw_copies',
            'monthly_limit' => 1000,
            'current_usage' => 500,
        ]);

        $this->service->returnUsage('RetDept', 200);

        $limit->refresh();
        $this->assertEquals(300, $limit->current_usage);
    }

    public function test_return_usage_clamps_to_zero(): void
    {
        $dept = Department::create(['name' => 'ClampDept', 'type' => 'department', 'is_active' => true]);
        $limit = DepartmentLimit::create([
            'department_id' => $dept->id,
            'limit_type'    => 'bw_copies',
            'monthly_limit' => 1000,
            'current_usage' => 50,
        ]);

        // Trying to return 200 when only 50 used — should clamp
        $this->service->returnUsage('ClampDept', 200);

        $limit->refresh();
        $this->assertEquals(0, $limit->current_usage);
    }

    public function test_return_usage_skips_unknown_department(): void
    {
        // Should not throw — silently skips
        $this->service->returnUsage('НеІснуючий', 100);
        $this->assertTrue(true); // If we got here, no exception
    }
}
