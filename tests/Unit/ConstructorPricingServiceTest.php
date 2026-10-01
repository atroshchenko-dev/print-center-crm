<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Service;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Services\ConstructorPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConstructorPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConstructorPricingService $svc;
    private Service $service;
    private ServiceParameterGroup $formatGroup;
    private ServiceParameterOption $a4Option;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(ConstructorPricingService::class);

        // Create a constructor service: base = 2.00 грн/шт
        $this->service = Service::factory()->create([
            'type'                  => 'constructor',
            'base_price_commercial' => 2.00,
            'base_price_cost'       => 0.50,
            'clicks_per_unit'       => 1,
            'counter_type'          => 'bw',
        ]);

        // A "Format" radio group
        $this->formatGroup = ServiceParameterGroup::factory()->create([
            'service_id'  => $this->service->id,
            'ui_type'     => 'radio',
            'is_required' => true,
        ]);

        // A4 option: +1.00 markup, +1 bw click
        $this->a4Option = ServiceParameterOption::factory()->create([
            'group_id'        => $this->formatGroup->id,
            'price_markup'    => 1.00,
            'cost_markup'     => 0.20,
            'clicks_per_unit' => 1,
            'counter_type'    => 'bw',
            'is_active'       => true,
        ]);
    }

    /**
     * Base case: quantity 10, one option with +1.00 markup
     * Expected: (2.00 + 1.00) * 10 = 30.00
     */
    public function test_calculates_total_commercial_price_correctly(): void
    {
        $result = $this->svc->calculate(
            service:          $this->service,
            selectedOptions:  collect([$this->a4Option]),
            quantity:         10,
        );

        $this->assertEquals(3.00, $result['unit_price_commercial']);
        $this->assertEquals(30.00, $result['total_price_commercial']);
    }

    /**
     * Cost price: (0.50 + cost_markup) * 10
     * cost_markup is auto-calculated by ServiceParameterOption::booted()
     */
    public function test_calculates_total_cost_price_correctly(): void
    {
        $result = $this->svc->calculate(
            service:          $this->service,
            selectedOptions:  collect([$this->a4Option]),
            quantity:         10,
        );

        // cost_markup is auto-computed by the model's boot method (consumables + amortization)
        // Since we don't have inventory items or materials, it'll be 0.0000
        $expectedUnitCost = 0.50 + (float) $this->a4Option->fresh()->cost_markup;
        $this->assertEquals(round($expectedUnitCost, 2), $result['unit_price_cost']);
        $this->assertEquals(round($expectedUnitCost * 10, 2), $result['total_price_cost']);
    }

    /**
     * BW clicks: (service clicks_per_unit=1 from option) * 10 = 10
     */
    public function test_calculates_hardware_counters_correctly(): void
    {
        $result = $this->svc->calculate(
            service:          $this->service,
            selectedOptions:  collect([$this->a4Option]),
            quantity:         10,
        );

        // Option has clicks_per_unit=1, counter_type=bw → total bw_clicks = 1 * 10 = 10
        $this->assertEquals(10, $result['bw_clicks']);
    }

    /**
     * JSONB snapshot must contain option details for traceability.
     */
    public function test_constructor_snapshot_contains_option_name(): void
    {
        $result = $this->svc->calculate(
            service:          $this->service,
            selectedOptions:  collect([$this->a4Option]),
            quantity:         5,
        );

        $snapshot = $result['constructor_snapshot'] ?? [];
        $this->assertNotEmpty($snapshot);

        $optionNames = array_column($snapshot, 'option_name');
        $this->assertContains($this->a4Option->name, $optionNames);
    }

    /**
     * No selected options: price must equal base price only.
     */
    public function test_price_equals_base_when_no_options_selected(): void
    {
        // Make the group optional for this test
        $this->formatGroup->update(['is_required' => false]);

        $result = $this->svc->calculate(
            service:          $this->service,
            selectedOptions:  collect([]),
            quantity:         1,
        );

        $this->assertEquals(2.00, $result['unit_price_commercial']);
        $this->assertEquals(2.00, $result['total_price_commercial']);
    }

    // ─── Static service counter mapping ──────────

    /**
     * A static service has no options, so its counter mapping lives on the
     * service row itself (ТЗ §3.3). Nothing read those two columns until
     * R11-3, so every static run recorded zero clicks and quietly enlarged
     * the "unaccounted" delta on the counter report.
     */
    public function test_static_service_contributes_its_own_clicks(): void
    {
        $static = Service::factory()->create([
            'type'            => 'static',
            'base_price_cost' => 1.00,
            'counter_type'    => 'bw',
            'clicks_per_unit' => 5,
        ]);

        $result = $this->svc->calculate(
            service:         $static,
            selectedOptions: collect([]),
            quantity:        10,
        );

        $this->assertEquals(50, $result['bw_clicks']);
        $this->assertEquals(0, $result['color_clicks']);
        $this->assertEquals(0, $result['riso_clicks']);
    }

    /**
     * OrderItemBuilder copies bw_clicks straight out of hardware_counters,
     * so the snapshot has to carry the same numbers for them to reach the row.
     */
    public function test_static_service_clicks_reach_the_snapshot(): void
    {
        $static = Service::factory()->create([
            'type'            => 'static',
            'counter_type'    => 'color',
            'clicks_per_unit' => 2,
        ]);

        $snapshot = $this->svc->buildSnapshot(
            service:         $static,
            selectedOptions: collect([]),
            quantity:        7,
        );

        $this->assertEquals(14, $snapshot['hardware_counters']['color_clicks']);
        $this->assertEquals(0, $snapshot['hardware_counters']['bw_clicks']);
    }

    /**
     * 'none' is what the seeded static services carry (laminating, scanning):
     * they consume no clicks and must stay at zero even with a stale count.
     */
    public function test_static_service_without_counter_records_no_clicks(): void
    {
        $static = Service::factory()->create([
            'type'            => 'static',
            'counter_type'    => 'none',
            'clicks_per_unit' => 5,
        ]);

        $result = $this->svc->calculate(
            service:         $static,
            selectedOptions: collect([]),
            quantity:        10,
        );

        $this->assertEquals(0, $result['bw_clicks']);
    }

    /**
     * The service row of a constructor also carries counter_type/clicks_per_unit
     * (setUp sets bw/1 here), but constructors map counters per option. Adding
     * both would double every click, so the service-level mapping is ignored.
     */
    public function test_constructor_ignores_service_level_counter_mapping(): void
    {
        $result = $this->svc->calculate(
            service:         $this->service,
            selectedOptions: collect([$this->a4Option]),
            quantity:        10,
        );

        // The option contributes 1 click/unit; the service row's own bw/1 must not.
        $this->assertEquals(10, $result['bw_clicks']);
    }
}
