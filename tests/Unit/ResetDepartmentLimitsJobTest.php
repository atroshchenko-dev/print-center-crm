<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\ResetDepartmentLimitsJob;
use App\Models\Department;
use App\Models\DepartmentLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ResetDepartmentLimitsJobTest — verifies monthly limit reset.
 */
class ResetDepartmentLimitsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_sets_all_current_usage_to_zero(): void
    {
        $dept1 = Department::create(['name' => 'Dept A', 'type' => 'department', 'is_active' => true]);
        $dept2 = Department::create(['name' => 'Dept B', 'type' => 'department', 'is_active' => true]);

        $limit1 = DepartmentLimit::create([
            'department_id' => $dept1->id, 'limit_type' => 'bw_copies',
            'monthly_limit' => 1000, 'current_usage' => 750,
        ]);
        $limit2 = DepartmentLimit::create([
            'department_id' => $dept2->id, 'limit_type' => 'bw_copies',
            'monthly_limit' => 500, 'current_usage' => 500,
        ]);

        (new ResetDepartmentLimitsJob())->handle();

        $limit1->refresh();
        $limit2->refresh();

        $this->assertEquals(0, $limit1->current_usage);
        $this->assertEquals(0, $limit2->current_usage);
        $this->assertNotNull($limit1->reset_at);
        $this->assertNotNull($limit2->reset_at);
    }
}
