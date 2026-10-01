<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\RisoPriceTier;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Services\RisoPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RisoPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private RisoPricingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Each test gets a fresh service instance (clears static cache)
        $this->service = new RisoPricingService;

        // Clear migration-seeded production tiers before inserting test-specific ones
        RisoPriceTier::query()->forceDelete();

        // Seed tiers: 1-10, 11-50, 51+
        RisoPriceTier::create(['min_qty' => 1,  'max_qty' => 10,   'cost_per_copy' => 5.00]);
        RisoPriceTier::create(['min_qty' => 11, 'max_qty' => 50,   'cost_per_copy' => 3.00]);
        RisoPriceTier::create(['min_qty' => 51, 'max_qty' => null,  'cost_per_copy' => 2.00]);
    }

    public function test_calculates_basic_a3_pricing(): void
    {
        $result = $this->service->calculate(copies: 5, format: 'A3', sides: 1);

        $this->assertEquals(5, $result['sheets_a3']);
        $this->assertEquals(5.00, $result['cost_per_copy']);
        $this->assertEquals(25.00, $result['print_cost']);  // 5 × 5.00 × 1
        $this->assertArrayHasKey('paper_cost', $result);
        $this->assertArrayHasKey('total_cost', $result);
        $this->assertEquals('1–10', $result['tier_label']);
    }

    public function test_converts_a4_copies_to_a3_sheets(): void
    {
        // 10 A4 copies = 5 A3 sheets
        $result = $this->service->calculate(copies: 10, format: 'A4', sides: 1);

        $this->assertEquals(5, $result['sheets_a3']);
    }

    public function test_rounds_up_a4_to_a3_conversion(): void
    {
        // 11 A4 = ceil(11/2) = 6 A3 sheets
        $result = $this->service->calculate(copies: 11, format: 'A4', sides: 1);

        $this->assertEquals(6, $result['sheets_a3']);
    }

    /**
     * The A4 halving is per original, because a risograph burns one master per
     * original and two different originals cannot share one A3 sheet.
     *
     * `RisoCalculator.vue` has always summed it this way; the server rounded the
     * whole run instead and no request field carried `originals` at all, so it
     * could not have done otherwise.
     */
    public function test_a4_sheets_are_rounded_per_original_not_over_the_whole_run(): void
    {
        // 3 originals × 5 copies each = 3 × ceil(5/2) = 9 sheets, not ceil(15/2) = 8
        $result = $this->service->calculate(copies: 15, format: 'A4', sides: 1, originals: 3);

        $this->assertEquals(9, $result['sheets_a3']);
    }

    public function test_an_even_copy_count_leaves_both_ways_of_counting_equal(): void
    {
        // 4 originals × 6 copies: nothing is rounded either way.
        $wholeRun = $this->service->calculate(copies: 24, format: 'A4', sides: 1);
        $perOriginal = $this->service->calculate(copies: 24, format: 'A4', sides: 1, originals: 4);

        $this->assertEquals(12, $wholeRun['sheets_a3']);
        $this->assertEquals(12, $perOriginal['sheets_a3']);
    }

    /**
     * Why this is money rather than a rounding curiosity: the sheet count picks
     * the tier, and the tier prices *every* sheet in the run.
     *
     * This test runs against the fixture tiers of setUp(), not the seeded ones.
     * On the production ladder the case that bites is 2 originals × 99 A4
     * copies: 100 sheets sits in 100–149 at 0.23 (23.00), while rounding the
     * whole 198 copies gives 99 sheets in 50–99 at 0.41 (40.59) — the same job,
     * priced 17.59 apart, and the cheaper number is the correct one. The
     * direction is not fixed: which way it lands depends on which boundary the
     * lost sheet is next to.
     */
    public function test_the_sheet_that_rounding_lost_moves_the_whole_run_to_another_tier(): void
    {
        $perOriginal = $this->service->calculate(copies: 99, format: 'A4', sides: 1, originals: 3);

        $this->assertEquals(51, $perOriginal['sheets_a3']);
        $this->assertEquals('51+', $perOriginal['tier_label']);
        $this->assertEquals(102.00, $perOriginal['print_cost']);

        // What the server used to compute for the very same job.
        $wholeRun = $this->service->calculate(copies: 99, format: 'A4', sides: 1);

        $this->assertEquals(50, $wholeRun['sheets_a3']);
        $this->assertEquals('11–50', $wholeRun['tier_label']);
        $this->assertEquals(150.00, $wholeRun['print_cost']);
    }

    /**
     * The same crossing on the tiers production actually has, so the registry
     * and the brief cannot quote a number that only exists in a fixture.
     */
    public function test_the_boundary_that_bites_on_the_seeded_ladder(): void
    {
        RisoPriceTier::query()->forceDelete();
        RisoPriceTier::create(['min_qty' => 50,  'max_qty' => 99,   'cost_per_copy' => 0.4100]);
        RisoPriceTier::create(['min_qty' => 100, 'max_qty' => 149,  'cost_per_copy' => 0.2300]);

        // 2 originals × 99 A4 copies each.
        $perOriginal = $this->service->calculate(copies: 198, format: 'A4', sides: 1, originals: 2);
        $wholeRun = $this->service->calculate(copies: 198, format: 'A4', sides: 1);

        $this->assertEquals(100, $perOriginal['sheets_a3']);
        $this->assertEquals(23.00, $perOriginal['print_cost']);

        $this->assertEquals(99, $wholeRun['sheets_a3']);
        $this->assertEquals(40.59, $wholeRun['print_cost']);
    }

    public function test_a3_is_unaffected_because_nothing_is_halved(): void
    {
        $result = $this->service->calculate(copies: 15, format: 'A3', sides: 1, originals: 3);

        $this->assertEquals(15, $result['sheets_a3']);
    }

    public function test_doubles_print_cost_for_two_sides(): void
    {
        $oneSide = $this->service->calculate(copies: 5, format: 'A3', sides: 1);
        $twoSide = $this->service->calculate(copies: 5, format: 'A3', sides: 2);

        $this->assertEquals($oneSide['print_cost'] * 2, $twoSide['print_cost']);
        // Paper cost stays the same (same number of sheets)
        $this->assertEquals($oneSide['paper_cost'], $twoSide['paper_cost']);
    }

    public function test_selects_correct_tier_for_quantity(): void
    {
        $tier1 = $this->service->calculate(copies: 5, format: 'A3', sides: 1);
        $this->assertEquals(5.00, $tier1['cost_per_copy']);

        $tier2 = $this->service->calculate(copies: 20, format: 'A3', sides: 1);
        $this->assertEquals(3.00, $tier2['cost_per_copy']);

        $tier3 = $this->service->calculate(copies: 100, format: 'A3', sides: 1);
        $this->assertEquals(2.00, $tier3['cost_per_copy']);
    }

    public function test_uses_open_ended_tier_for_large_quantities(): void
    {
        $result = $this->service->calculate(copies: 1000, format: 'A3', sides: 1);

        $this->assertEquals(2.00, $result['cost_per_copy']);
        $this->assertEquals('51+', $result['tier_label']);
    }

    public function test_includes_paper_cost_in_total(): void
    {
        config(['riso.paper_cost_a3' => 1.00]);

        $result = $this->service->calculate(copies: 10, format: 'A3', sides: 1);

        $expectedPaper = 10 * 1.00;
        $expectedPrint = 10 * 5.00 * 1; // tier 1-10
        $this->assertEquals($expectedPaper, $result['paper_cost']);
        $this->assertEquals($expectedPrint + $expectedPaper, $result['total_cost']);
    }

    public function test_throws_when_no_tier_found(): void
    {
        // Delete all tiers
        RisoPriceTier::query()->forceDelete();

        $this->expectException(\RuntimeException::class);
        $this->service->calculate(copies: 5, format: 'A3', sides: 1);
    }

    public function test_uses_dynamic_paper_cost_from_inventory(): void
    {
        // Create inventory item with specific avg_cost
        $category = InventoryCategory::firstOrCreate(
            ['name' => 'Папір'],
            ['sort_order' => 1]
        );
        InventoryItem::create([
            'inventory_category_id' => $category->id,
            'name'                  => 'Папір А3 80 г/м²',
            'unit'                  => 'аркуш',
            'current_quantity'      => 1000,
            'avg_cost'              => 1.50,
            'min_quantity'          => 200,
            'is_active'             => true,
        ]);

        // Fresh service instance to clear static cache
        $service = new RisoPricingService;
        $result = $service->calculate(copies: 10, format: 'A3', sides: 1);

        // Paper cost should use avg_cost (1.50) not config default (0.80)
        $expectedPaper = 10 * 1.50;
        $this->assertEquals($expectedPaper, $result['paper_cost']);
    }

    public function test_falls_back_to_config_when_no_inventory_item(): void
    {
        config(['riso.paper_cost_a3' => 0.80]);

        // No inventory item created — should fall back to config
        $result = $this->service->calculate(copies: 10, format: 'A3', sides: 1);

        $expectedPaper = 10 * 0.80;
        $this->assertEquals($expectedPaper, $result['paper_cost']);
    }

    public function test_snapshot_includes_inventory_deductions(): void
    {
        // Create inventory item
        $category = InventoryCategory::firstOrCreate(
            ['name' => 'Папір'],
            ['sort_order' => 1]
        );
        $paperItem = InventoryItem::create([
            'inventory_category_id' => $category->id,
            'name'                  => 'Папір А3 80 г/м²',
            'unit'                  => 'аркуш',
            'current_quantity'      => 1000,
            'avg_cost'              => 1.00,
            'min_quantity'          => 200,
            'is_active'             => true,
        ]);

        // Create a riso service for snapshot
        $cat = ServiceCategory::firstOrCreate(
            ['name' => 'Тиражування'],
            ['sort_order' => 1, 'is_active' => true]
        );
        $service = Service::create([
            'service_category_id'   => $cat->id,
            'name'                  => 'Ризограф',
            'type'                  => 'riso',
            'base_price_commercial' => 0,
            'base_price_cost'       => 0,
            'counter_type'          => 'riso',
            'clicks_per_unit'       => 0,
            'is_active'             => true,
        ]);

        $risoService = new RisoPricingService;
        $snapshot = $risoService->buildSnapshot($service, copies: 10, format: 'A3', sides: 1);

        // Snapshot should include inventory_deductions
        $this->assertArrayHasKey('inventory_deductions', $snapshot);
        $this->assertCount(1, $snapshot['inventory_deductions']);
        $this->assertEquals($paperItem->id, $snapshot['inventory_deductions'][0]['inventory_item_id']);
        $this->assertEquals(1, $snapshot['inventory_deductions'][0]['qty']); // 1 A3 sheet per copy
    }

    public function test_snapshot_uses_explicit_paper_id(): void
    {
        // Create two paper items with different costs
        $category = InventoryCategory::firstOrCreate(
            ['name' => 'Папір'],
            ['sort_order' => 1]
        );
        $paper80 = InventoryItem::create([
            'inventory_category_id' => $category->id,
            'name'                  => 'Папір А3 80 г/м²',
            'unit'                  => 'аркуш',
            'current_quantity'      => 1000,
            'avg_cost'              => 0.87,
            'min_quantity'          => 200,
            'is_active'             => true,
        ]);
        $paper160 = InventoryItem::create([
            'inventory_category_id' => $category->id,
            'name'                  => 'Папір А3 160 г/м²',
            'unit'                  => 'аркуш',
            'current_quantity'      => 500,
            'avg_cost'              => 2.02,
            'min_quantity'          => 100,
            'is_active'             => true,
        ]);

        $cat = ServiceCategory::firstOrCreate(
            ['name' => 'Тиражування'],
            ['sort_order' => 1, 'is_active' => true]
        );
        $service = Service::create([
            'service_category_id'   => $cat->id,
            'name'                  => 'Ризограф',
            'type'                  => 'riso',
            'base_price_commercial' => 0,
            'base_price_cost'       => 0,
            'counter_type'          => 'riso',
            'clicks_per_unit'       => 0,
            'is_active'             => true,
        ]);

        // Build snapshot with explicit 160g paper
        $risoService = new RisoPricingService;
        $snapshot = $risoService->buildSnapshot($service, copies: 10, format: 'A3', sides: 1, paperId: $paper160->id);

        // Paper cost should use 160g avg_cost (2.02), not 80g (0.87)
        $this->assertEquals(2.02, $snapshot['riso_params']['paper_cost_a3']);
        $this->assertEquals('Папір А3 160 г/м²', $snapshot['riso_params']['paper_name']);

        // Inventory deduction should reference 160g paper, not 80g
        $this->assertCount(1, $snapshot['inventory_deductions']);
        $this->assertEquals($paper160->id, $snapshot['inventory_deductions'][0]['inventory_item_id']);

        // Expected paper cost: 10 sheets × 2.02 = 20.20
        $expectedPaperCost = 10 * 2.02;
        $printCost = 10 * 5.00; // tier 1-10, 1 side
        $this->assertEquals(round($printCost + $expectedPaperCost, 2), $snapshot['total_price_cost']);
    }

    /**
     * The commercial price is the cost times the setting.
     *
     * `RisoCalculator` used to send the **cost** into the cart as the
     * commercial figure, so a commercial Riso order was quoted at half. It no
     * longer sends one at all — the page is not given this multiplier and
     * cannot know it — which makes this the only place the number is decided.
     * Pinned here so it stays a place rather than becoming two again.
     */
    public function test_the_commercial_price_is_the_cost_times_the_setting(): void
    {
        Setting::setValue('riso_commercial_markup', 2.5);

        $service = Service::create([
            'service_category_id' => ServiceCategory::firstOrCreate(
                ['name' => 'Тиражування'],
                ['sort_order' => 1, 'is_active' => true],
            )->id,
            'name'                  => 'Ризограф',
            'type'                  => 'riso',
            'base_price_commercial' => 0,
            'base_price_cost'       => 0,
            'counter_type'          => 'riso',
            'clicks_per_unit'       => 0,
            'is_active'             => true,
        ]);

        config(['riso.paper_cost_a3' => 0.0]);

        $snapshot = (new RisoPricingService)->buildSnapshot($service, copies: 10, format: 'A3', sides: 1);

        $this->assertSame(50.0, (float) $snapshot['total_price_cost'], '10 × 5,00 за тарифом 1–10');
        $this->assertSame(125.0, (float) $snapshot['total_price_commercial'], 'собівартість × 2,5');
        $this->assertSame(2.5, (float) $snapshot['riso_params']['markup']);
    }
}
