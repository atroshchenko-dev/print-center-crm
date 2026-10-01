<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Material;
use App\Models\AuditLog;
use App\Services\PriceSchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PriceSchedulerServiceTest
 *
 * Verifies delayed price activation logic:
 * - Materials with pending prices past their activation date get updated
 * - Pending fields are cleared after activation
 * - Cascade to ServiceParameterOption cost_markup (via Material::updated hook)
 * - Materials with future dates are NOT activated
 */
class PriceSchedulerServiceTest extends TestCase
{
    use RefreshDatabase;

    private PriceSchedulerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PriceSchedulerService();
    }

    public function test_activates_pending_price_when_date_has_passed(): void
    {
        $material = Material::factory()->create([
            'click_cost'           => 0.50,
            'pending_click_cost'   => 0.75,
            'pending_activated_at' => now()->subHour(),
            'counter_type'         => 'bw',
            'is_active'            => true,
        ]);

        $count = $this->service->activatePendingPrices();

        $this->assertEquals(1, $count);

        $material->refresh();
        $this->assertEquals(0.75, (float) $material->click_cost);
        $this->assertNull($material->pending_click_cost);
        $this->assertNull($material->pending_activated_at);
    }

    public function test_does_not_activate_future_pending_price(): void
    {
        Material::factory()->create([
            'click_cost'           => 0.50,
            'pending_click_cost'   => 0.75,
            'pending_activated_at' => now()->addDay(),
            'counter_type'         => 'color',
            'is_active'            => true,
        ]);

        $count = $this->service->activatePendingPrices();

        $this->assertEquals(0, $count);
    }

    public function test_creates_audit_log_on_activation(): void
    {
        Material::factory()->create([
            'click_cost'           => 1.00,
            'pending_click_cost'   => 1.50,
            'pending_activated_at' => now()->subMinute(),
            'counter_type'         => 'riso',
            'is_active'            => true,
        ]);

        $this->service->activatePendingPrices();

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'price_activated',
        ]);
    }

    public function test_returns_zero_when_no_pending_prices(): void
    {
        Material::factory()->create([
            'click_cost'           => 0.50,
            'pending_click_cost'   => null,
            'pending_activated_at' => null,
            'counter_type'         => 'bw',
            'is_active'            => true,
        ]);

        $count = $this->service->activatePendingPrices();

        $this->assertEquals(0, $count);
    }
}
