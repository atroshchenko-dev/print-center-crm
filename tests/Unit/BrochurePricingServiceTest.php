<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\Service;
use App\Services\BrochurePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrochurePricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private BrochurePricingService $service;
    private Service $brochureService;
    private InventoryItem $coverPaper;
    private InventoryItem $blockPaper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BrochurePricingService();

        // Create materials for click cost lookup
        Material::factory()->create([
            'name'         => 'BW Material',
            'counter_type' => 'bw',
            'click_cost'   => 0.12,
            'is_active'    => true,
        ]);
        Material::factory()->create([
            'name'         => 'Color Material',
            'counter_type' => 'color',
            'click_cost'   => 0.60,
            'is_active'    => true,
        ]);

        // Create a brochure service
        $this->brochureService = Service::factory()->create([
            'name' => 'Брошури',
            'type' => 'brochure',
        ]);

        // Create inventory items for paper
        $category = \App\Models\InventoryCategory::factory()->create(['name' => 'Папір']);

        $this->coverPaper = InventoryItem::factory()->create([
            'name'                  => 'Папір А3 щільний',
            'inventory_category_id' => $category->id,
            'avg_cost'              => 2.50,
            'current_quantity'      => 100,
            'is_active'             => true,
        ]);
        $this->blockPaper = InventoryItem::factory()->create([
            'name'                  => 'Папір А4 80г',
            'inventory_category_id' => $category->id,
            'avg_cost'              => 0.80,
            'current_quantity'      => 500,
            'is_active'             => true,
        ]);
    }

    // ─── Cover Cost Calculation ──────────────────────────

    public function test_calculates_cover_cost_correctly_for_bw_a4_brochure(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->brochureService,
            [
                'format'         => 'А4',
                'cover_paper_id' => $this->coverPaper->id,
                'cover_mode'     => '1+0',
                'block_paper_id' => $this->blockPaper->id,
                'block_entries'  => [],
            ],
            quantity: 10,
        );

        // А4 brochure → printed on А3 → multiplier = 2
        // Mode '1+0': bw = 1 * 2 = 2 clicks, color = 0
        // Cover cost per brochure = paper(2.50) + clicks(2 * 0.12) = 2.50 + 0.24 = 2.74
        $this->assertEquals(2, $snapshot['brochure_params']['cover']['bw_clicks']);
        $this->assertEquals(0, $snapshot['brochure_params']['cover']['color_clicks']);
        $this->assertEqualsWithDelta(2.74, $snapshot['brochure_params']['cover']['unit_cost'], 0.01);
    }

    public function test_calculates_cover_cost_correctly_for_color_a5_brochure(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->brochureService,
            [
                'format'         => 'А5',
                'cover_paper_id' => $this->coverPaper->id,
                'cover_mode'     => '4+0',
                'block_paper_id' => $this->blockPaper->id,
                'block_entries'  => [],
            ],
            quantity: 5,
        );

        // А5 brochure → printed on А4 → multiplier = 1
        // Mode '4+0': bw = 0, color = 1 * 1 = 1 click
        // Cover cost per brochure = paper(2.50) + clicks(1 * 0.60) = 2.50 + 0.60 = 3.10
        $this->assertEquals(0, $snapshot['brochure_params']['cover']['bw_clicks']);
        $this->assertEquals(1, $snapshot['brochure_params']['cover']['color_clicks']);
        $this->assertEqualsWithDelta(3.10, $snapshot['brochure_params']['cover']['unit_cost'], 0.01);
    }

    // ─── Block Cost Calculation ──────────────────────────

    public function test_calculates_block_cost_with_mixed_modes(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->brochureService,
            [
                'format'         => 'А4',
                'cover_paper_id' => $this->coverPaper->id,
                'cover_mode'     => '1+0',
                'block_paper_id' => $this->blockPaper->id,
                'block_entries'  => [
                    ['mode' => '1+0', 'sheets' => 10],  // BW single-sided
                    ['mode' => '4+4', 'sheets' => 5],   // Color double-sided
                ],
            ],
            quantity: 1,
        );

        $block = $snapshot['brochure_params']['block'];

        $this->assertEquals(15, $block['total_sheets']);

        // Entry 1: 10 sheets, mode '1+0', А4 brochure → mult=2
        //   bw = 1*2*10 = 20, color = 0
        //   cost = 10 * 0.80 + 20 * 0.12 = 8.00 + 2.40 = 10.40
        // Entry 2: 5 sheets, mode '4+4', А4 brochure → mult=2
        //   bw = 0, color = 2*2*5 = 20
        //   cost = 5 * 0.80 + 20 * 0.60 = 4.00 + 12.00 = 16.00
        // Total block = 10.40 + 16.00 = 26.40
        $this->assertEqualsWithDelta(26.40, $block['unit_cost'], 0.01);
    }

    // ─── A4 vs A5 Click Multiplier ──────────────────────

    public function test_a4_brochure_doubles_clicks(): void
    {
        $snapshotA4 = $this->service->buildSnapshot(
            $this->brochureService,
            [
                'format'         => 'А4',
                'cover_paper_id' => $this->coverPaper->id,
                'cover_mode'     => '1+1',
                'block_paper_id' => $this->blockPaper->id,
                'block_entries'  => [],
            ],
            quantity: 1,
        );

        $snapshotA5 = $this->service->buildSnapshot(
            $this->brochureService,
            [
                'format'         => 'А5',
                'cover_paper_id' => $this->coverPaper->id,
                'cover_mode'     => '1+1',
                'block_paper_id' => $this->blockPaper->id,
                'block_entries'  => [],
            ],
            quantity: 1,
        );

        // '1+1' mode: bw = 2 * multiplier per side
        // А4: multiplier = 2 → bw = 2*2 = 4
        // А5: multiplier = 1 → bw = 2*1 = 2
        $this->assertEquals(4, $snapshotA4['brochure_params']['cover']['bw_clicks']);
        $this->assertEquals(2, $snapshotA5['brochure_params']['cover']['bw_clicks']);
    }

    // ─── Deduction Merging ──────────────────────────────

    public function test_merges_deductions_for_same_paper(): void
    {
        // Use the same paper for cover and block
        $snapshot = $this->service->buildSnapshot(
            $this->brochureService,
            [
                'format'         => 'А5',
                'cover_paper_id' => $this->coverPaper->id,
                'cover_mode'     => '1+0',
                'block_paper_id' => $this->coverPaper->id, // Same as cover
                'block_entries'  => [
                    ['mode' => '1+0', 'sheets' => 3],
                ],
            ],
            quantity: 1,
        );

        // Cover = 1 sheet, Block = 3 sheets, same paper → merged to 4
        $this->assertCount(1, $snapshot['inventory_deductions']);
        $this->assertEquals($this->coverPaper->id, $snapshot['inventory_deductions'][0]['inventory_item_id']);
        $this->assertEquals(4, $snapshot['inventory_deductions'][0]['qty']);
    }

    public function test_separate_deductions_for_different_papers(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->brochureService,
            [
                'format'         => 'А5',
                'cover_paper_id' => $this->coverPaper->id,
                'cover_mode'     => '1+0',
                'block_paper_id' => $this->blockPaper->id, // Different paper
                'block_entries'  => [
                    ['mode' => '1+0', 'sheets' => 5],
                ],
            ],
            quantity: 1,
        );

        // Cover = 1 sheet (coverPaper), Block = 5 sheets (blockPaper) → 2 separate deductions
        $this->assertCount(2, $snapshot['inventory_deductions']);
        $this->assertEquals($this->coverPaper->id, $snapshot['inventory_deductions'][0]['inventory_item_id']);
        $this->assertEquals(1, $snapshot['inventory_deductions'][0]['qty']);
        $this->assertEquals($this->blockPaper->id, $snapshot['inventory_deductions'][1]['inventory_item_id']);
        $this->assertEquals(5, $snapshot['inventory_deductions'][1]['qty']);
    }

    // ─── Empty Block ────────────────────────────────────

    public function test_empty_block_entries_produce_zero_block_cost(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->brochureService,
            [
                'format'         => 'А4',
                'cover_paper_id' => $this->coverPaper->id,
                'cover_mode'     => '1+0',
                'block_paper_id' => $this->blockPaper->id,
                'block_entries'  => [],
            ],
            quantity: 10,
        );

        $this->assertEquals(0, $snapshot['brochure_params']['block']['total_sheets']);
        $this->assertEqualsWithDelta(0, $snapshot['brochure_params']['block']['unit_cost'], 0.001);
    }

    // ─── Total Cost Calculation ─────────────────────────

    public function test_total_cost_scales_with_quantity(): void
    {
        $snapshot1 = $this->service->buildSnapshot(
            $this->brochureService,
            [
                'format'         => 'А5',
                'cover_paper_id' => $this->coverPaper->id,
                'cover_mode'     => '1+0',
                'block_paper_id' => $this->blockPaper->id,
                'block_entries'  => [
                    ['mode' => '1+0', 'sheets' => 2],
                ],
            ],
            quantity: 1,
        );

        $snapshot10 = $this->service->buildSnapshot(
            $this->brochureService,
            [
                'format'         => 'А5',
                'cover_paper_id' => $this->coverPaper->id,
                'cover_mode'     => '1+0',
                'block_paper_id' => $this->blockPaper->id,
                'block_entries'  => [
                    ['mode' => '1+0', 'sheets' => 2],
                ],
            ],
            quantity: 10,
        );

        // total_price_cost for qty=10 should be 10× qty=1
        $this->assertEqualsWithDelta(
            $snapshot1['total_price_cost'] * 10,
            $snapshot10['total_price_cost'],
            0.01,
        );

        // Click totals should also scale
        $this->assertEquals(
            $snapshot1['hardware_counters']['bw_clicks'] * 10,
            $snapshot10['hardware_counters']['bw_clicks'],
        );
    }

    // ─── Snapshot Structure ─────────────────────────────

    public function test_snapshot_contains_required_keys(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->brochureService,
            [
                'format'         => 'А4',
                'cover_paper_id' => $this->coverPaper->id,
                'cover_mode'     => '1+0',
                'block_paper_id' => $this->blockPaper->id,
                'block_entries'  => [],
            ],
            quantity: 1,
        );

        $this->assertArrayHasKey('service_id', $snapshot);
        $this->assertArrayHasKey('service_name', $snapshot);
        $this->assertArrayHasKey('service_type', $snapshot);
        $this->assertEquals('brochure', $snapshot['service_type']);
        $this->assertArrayHasKey('unit_price_cost', $snapshot);
        $this->assertArrayHasKey('total_price_cost', $snapshot);
        $this->assertArrayHasKey('hardware_counters', $snapshot);
        $this->assertArrayHasKey('brochure_params', $snapshot);
        $this->assertArrayHasKey('inventory_deductions', $snapshot);

        // Brochures are offered internally, but if one ends up on a
        // commercial order it is priced at 2× cost rather than 0.
        $this->assertEqualsWithDelta(
            $snapshot['unit_price_cost'] * 2,
            $snapshot['unit_price_commercial'],
            0.0001,
        );
        $this->assertEqualsWithDelta(
            $snapshot['total_price_cost'] * 2,
            $snapshot['total_price_commercial'],
            0.01,
        );
        $this->assertGreaterThan(0, $snapshot['unit_price_commercial']);
    }
}
