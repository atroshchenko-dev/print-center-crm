<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\Service;
use App\Services\DiplomaPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DiplomaPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private DiplomaPricingService $service;

    private Service $diplomaService;

    private InventoryItem $paperA4_160;

    private InventoryItem $paperA3_160;

    private InventoryItem $paperA4_80;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DiplomaPricingService;

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

        // Create diploma service
        $this->diplomaService = Service::factory()->create([
            'name' => 'Дипломи та додатки',
            'type' => 'diploma',
        ]);

        // Create inventory items
        $category = InventoryCategory::factory()->create(['name' => 'Папір']);

        $this->paperA4_160 = InventoryItem::factory()->create([
            'name'                  => 'Папір А4 160 г/м²',
            'inventory_category_id' => $category->id,
            'avg_cost'              => 1.50,
            'current_quantity'      => 500,
            'is_active'             => true,
        ]);
        $this->paperA3_160 = InventoryItem::factory()->create([
            'name'                  => 'Папір А3 160 г/м²',
            'inventory_category_id' => $category->id,
            'avg_cost'              => 3.00,
            'current_quantity'      => 200,
            'is_active'             => true,
        ]);
        $this->paperA4_80 = InventoryItem::factory()->create([
            'name'                  => 'Папір А4 80 г/м²',
            'inventory_category_id' => $category->id,
            'avg_cost'              => 0.50,
            'current_quantity'      => 1000,
            'is_active'             => true,
        ]);
    }

    // ─── Section 1: Diplomas (A4 160g, 4+0 color) ───────

    public function test_calculates_diploma_cost_correctly(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            ['diplomas' => ['qty' => 10]],
            quantity: 1,
        );

        // 10 diplomas × 1 sheet A4 160g × 1 color click (4+0)
        // Cost = 10 × 1.50 (paper) + 10 × 0.60 (color click) = 15.00 + 6.00 = 21.00
        $this->assertEquals(10, $snapshot['hardware_counters']['color_clicks']);
        $this->assertEquals(0, $snapshot['hardware_counters']['bw_clicks']);
        $this->assertEqualsWithDelta(21.00, $snapshot['total_price_cost'], 0.01);
        $this->assertEquals(10, $snapshot['diploma_params']['diplomas']['qty']);
    }

    public function test_zero_diploma_qty_produces_zero_cost(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            ['diplomas' => ['qty' => 0]],
            quantity: 1,
        );

        $this->assertEquals(0, $snapshot['hardware_counters']['color_clicks']);
        $this->assertEqualsWithDelta(0, $snapshot['total_price_cost'], 0.01);
    }

    // ─── Section 2: Supplements (A4 160g, mixed color) ──

    public function test_calculates_supplement_cost_with_single_block(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            [
                'supplements' => [
                    [
                        'type'   => 'supplement_ua',
                        'label'  => 'Додаток (укр)',
                        'qty'    => 5,
                        'blocks' => [
                            ['sheets' => 3, 'mode' => '4+0'],
                        ],
                    ],
                ],
            ],
            quantity: 1,
        );

        // 5 supplements × 3 sheets × 1 color click (4+0) = 15 color clicks
        // Paper: 5 × 3 = 15 sheets A4 160g → 15 × 1.50 = 22.50
        // Clicks: 15 × 0.60 = 9.00
        // Total: 31.50
        $this->assertEquals(15, $snapshot['hardware_counters']['color_clicks']);
        $this->assertEqualsWithDelta(31.50, $snapshot['total_price_cost'], 0.01);
        $this->assertCount(1, $snapshot['diploma_params']['supplements']);
    }

    public function test_calculates_supplement_cost_with_mixed_blocks(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            [
                'supplements' => [
                    [
                        'type'   => 'supplement_ua',
                        'label'  => 'Додаток (укр)',
                        'qty'    => 2,
                        'blocks' => [
                            ['sheets' => 4, 'mode' => '4+0'],  // 1 click/sheet
                            ['sheets' => 2, 'mode' => '4+4'],  // 2 clicks/sheet
                        ],
                    ],
                ],
            ],
            quantity: 1,
        );

        // Block 1: 2 × 4 = 8 sheets, 2 × 4 × 1 = 8 color clicks
        // Block 2: 2 × 2 = 4 sheets, 2 × 2 × 2 = 8 color clicks
        // Total sheets: 12, Total color clicks: 16
        // Paper: 12 × 1.50 = 18.00
        // Clicks: 16 × 0.60 = 9.60
        // Total: 27.60
        $this->assertEquals(16, $snapshot['hardware_counters']['color_clicks']);
        $this->assertEqualsWithDelta(27.60, $snapshot['total_price_cost'], 0.01);
    }

    public function test_handles_multiple_supplement_types(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            [
                'supplements' => [
                    [
                        'type'   => 'supplement_ua',
                        'label'  => 'Додаток (укр)',
                        'qty'    => 1,
                        'blocks' => [['sheets' => 2, 'mode' => '4+0']],
                    ],
                    [
                        'type'   => 'supplement_en',
                        'label'  => 'Supplement (EN)',
                        'qty'    => 1,
                        'blocks' => [['sheets' => 3, 'mode' => '4+0']],
                    ],
                ],
            ],
            quantity: 1,
        );

        $this->assertCount(2, $snapshot['diploma_params']['supplements']);
        // 2 + 3 = 5 color clicks
        $this->assertEquals(5, $snapshot['hardware_counters']['color_clicks']);
    }

    // ─── Section 3: Academic Records (A3 160g, 4+4) ─────

    public function test_calculates_academic_records_cost(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            ['academic_records' => ['qty' => 8]],
            quantity: 1,
        );

        // 8 records × 1 A3 sheet × 2 color clicks (4+4)
        // Paper: 8 × 3.00 = 24.00
        // Clicks: 16 × 0.60 = 9.60
        // Total: 33.60
        $this->assertEquals(16, $snapshot['hardware_counters']['color_clicks']);
        $this->assertEquals(0, $snapshot['hardware_counters']['bw_clicks']);
        $this->assertEqualsWithDelta(33.60, $snapshot['total_price_cost'], 0.01);
    }

    // ─── Section 4: Diploma Copies (A4 80g, BW 1+0) ────

    public function test_calculates_diploma_copies_cost(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            ['copies' => ['diploma_copies' => ['qty' => 20]]],
            quantity: 1,
        );

        // 20 copies × 1 sheet A4 80g × 1 BW click (1+0)
        // Paper: 20 × 0.50 = 10.00
        // Clicks: 20 × 0.12 = 2.40
        // Total: 12.40
        $this->assertEquals(20, $snapshot['hardware_counters']['bw_clicks']);
        $this->assertEquals(0, $snapshot['hardware_counters']['color_clicks']);
        $this->assertEqualsWithDelta(12.40, $snapshot['total_price_cost'], 0.01);
    }

    // ─── Section 5: Supplement Copies (A4 80g, BW blocks) ─

    public function test_calculates_supplement_copies_cost(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            [
                'copies' => [
                    'supplement_copies' => [
                        'qty'    => 3,
                        'blocks' => [
                            ['sheets' => 4, 'mode' => '1+0'],  // 1 bw click/sheet
                            ['sheets' => 2, 'mode' => '1+1'],  // 2 bw clicks/sheet
                        ],
                    ],
                ],
            ],
            quantity: 1,
        );

        // Block 1: 3 × 4 = 12 sheets, 3 × 4 × 1 = 12 BW clicks
        // Block 2: 3 × 2 = 6 sheets, 3 × 2 × 2 = 12 BW clicks
        // Total sheets: 18, Total BW clicks: 24
        // Paper: 18 × 0.50 = 9.00
        // Clicks: 24 × 0.12 = 2.88
        // Total: 11.88
        $this->assertEquals(24, $snapshot['hardware_counters']['bw_clicks']);
        $this->assertEquals(0, $snapshot['hardware_counters']['color_clicks']);
        $this->assertEqualsWithDelta(11.88, $snapshot['total_price_cost'], 0.01);
    }

    // ─── Combined Sections ──────────────────────────────

    public function test_full_diploma_package_combines_all_sections(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            [
                'diplomas'    => ['qty' => 5],
                'supplements' => [
                    [
                        'type'   => 'supplement_ua',
                        'label'  => 'Додаток',
                        'qty'    => 5,
                        'blocks' => [['sheets' => 2, 'mode' => '4+0']],
                    ],
                ],
                'academic_records' => ['qty' => 5],
                'copies'           => [
                    'diploma_copies'    => ['qty' => 5],
                    'supplement_copies' => [
                        'qty'    => 5,
                        'blocks' => [['sheets' => 2, 'mode' => '1+0']],
                    ],
                ],
            ],
            quantity: 1,
        );

        // Diplomas: 5 color clicks
        // Supplements: 5 × 2 × 1 = 10 color clicks
        // Academic: 5 × 2 = 10 color clicks
        // Total color: 25
        $this->assertEquals(25, $snapshot['hardware_counters']['color_clicks']);

        // Diploma copies: 5 BW clicks
        // Supplement copies: 5 × 2 × 1 = 10 BW clicks
        // Total BW: 15
        $this->assertEquals(15, $snapshot['hardware_counters']['bw_clicks']);

        // RISO always 0
        $this->assertEquals(0, $snapshot['hardware_counters']['riso_clicks']);

        // Total cost > 0
        $this->assertGreaterThan(0, $snapshot['total_price_cost']);
    }

    // ─── Inventory Deductions ───────────────────────────

    public function test_merges_deductions_for_same_paper(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            [
                'diplomas'    => ['qty' => 3],       // 3 sheets A4 160g
                'supplements' => [
                    [
                        'type'   => 'supp',
                        'label'  => 'S',
                        'qty'    => 2,
                        'blocks' => [['sheets' => 1, 'mode' => '4+0']],
                    ],
                ],  // 2 sheets A4 160g
            ],
            quantity: 1,
        );

        // Both diplomas and supplements use A4 160g → merged to 5
        $a4_160_deductions = collect($snapshot['inventory_deductions'])
            ->where('inventory_item_id', $this->paperA4_160->id);

        $this->assertCount(1, $a4_160_deductions);
        $this->assertEquals(5, $a4_160_deductions->first()['qty']);
    }

    public function test_separate_deductions_for_different_papers(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            [
                'diplomas'         => ['qty' => 2],       // A4 160g
                'academic_records' => ['qty' => 3],       // A3 160g
                'copies'           => [
                    'diploma_copies' => ['qty' => 4],      // A4 80g
                ],
            ],
            quantity: 1,
        );

        // 3 different paper types → 3 deductions
        $this->assertCount(3, $snapshot['inventory_deductions']);

        $deductionMap = collect($snapshot['inventory_deductions'])->pluck('qty', 'inventory_item_id');
        $this->assertEquals(2, $deductionMap[$this->paperA4_160->id]);
        $this->assertEquals(3, $deductionMap[$this->paperA3_160->id]);
        $this->assertEquals(4, $deductionMap[$this->paperA4_80->id]);
    }

    // ─── Snapshot Structure ─────────────────────────────

    public function test_snapshot_contains_required_keys(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            ['diplomas' => ['qty' => 1]],
            quantity: 1,
        );

        $this->assertArrayHasKey('service_id', $snapshot);
        $this->assertArrayHasKey('service_name', $snapshot);
        $this->assertEquals('diploma', $snapshot['service_type']);
        $this->assertArrayHasKey('unit_price_cost', $snapshot);
        $this->assertArrayHasKey('total_price_cost', $snapshot);
        $this->assertArrayHasKey('hardware_counters', $snapshot);
        $this->assertArrayHasKey('diploma_params', $snapshot);
        $this->assertArrayHasKey('inventory_deductions', $snapshot);

        // Commercial always 0 (internal-only service)
        $this->assertEquals(0, $snapshot['unit_price_commercial']);
        $this->assertEquals(0, $snapshot['total_price_commercial']);

        // Diploma params structure
        $this->assertArrayHasKey('diplomas', $snapshot['diploma_params']);
        $this->assertArrayHasKey('supplements', $snapshot['diploma_params']);
        $this->assertArrayHasKey('academic_records', $snapshot['diploma_params']);
        $this->assertArrayHasKey('copies', $snapshot['diploma_params']);
        $this->assertArrayHasKey('paper_costs', $snapshot['diploma_params']);
    }

    public function test_paper_costs_captured_in_snapshot(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            ['diplomas' => ['qty' => 1]],
            quantity: 1,
        );

        $costs = $snapshot['diploma_params']['paper_costs'];
        $this->assertEqualsWithDelta(1.50, $costs['a4_160'], 0.01);
        $this->assertEqualsWithDelta(3.00, $costs['a3_160'], 0.01);
        $this->assertEqualsWithDelta(0.50, $costs['a4_80'], 0.01);
    }

    // ─── Edge Cases ─────────────────────────────────────

    public function test_empty_params_produce_zero_cost(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            [],
            quantity: 1,
        );

        $this->assertEqualsWithDelta(0, $snapshot['total_price_cost'], 0.01);
        $this->assertEquals(0, $snapshot['hardware_counters']['bw_clicks']);
        $this->assertEquals(0, $snapshot['hardware_counters']['color_clicks']);
        $this->assertEmpty($snapshot['inventory_deductions']);
    }

    public function test_supplement_with_zero_qty_is_skipped(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            [
                'supplements' => [
                    [
                        'type'   => 'supp',
                        'label'  => 'Empty',
                        'qty'    => 0,
                        'blocks' => [['sheets' => 5, 'mode' => '4+0']],
                    ],
                ],
            ],
            quantity: 1,
        );

        $this->assertEqualsWithDelta(0, $snapshot['total_price_cost'], 0.01);
        $this->assertEmpty($snapshot['diploma_params']['supplements']);
    }

    public function test_supplement_block_with_zero_sheets_is_skipped(): void
    {
        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            [
                'supplements' => [
                    [
                        'type'   => 'supp',
                        'label'  => 'S',
                        'qty'    => 5,
                        'blocks' => [
                            ['sheets' => 0, 'mode' => '4+0'],   // skipped
                            ['sheets' => 2, 'mode' => '4+0'],   // counted
                        ],
                    ],
                ],
            ],
            quantity: 1,
        );

        // Only 5 × 2 = 10 color clicks (from block 2)
        $this->assertEquals(10, $snapshot['hardware_counters']['color_clicks']);
    }

    // ─── What this service holds on to the stock room by ─

    /*
     * Three constructors reach into the same stock room and hold on to it three
     * different ways. The brochure holds a **key** — `InventoryItem::find($id)`
     * with the id off the form. RISO holds a name, but a name out of
     * `config/riso.php`, so a rename is answerable without a deploy. The diploma
     * holds three string literals written into the method, and when a lookup
     * misses, `resolveAvgCost(null)` returns `0.0` and `addDeduction(null, …)`
     * returns early — so the sheet becomes free and never leaves the shelf,
     * without a word anywhere.
     *
     * The click costs in this same file take the opposite view: `getClickCosts()`
     * falls back to 0.12 and 0.60 when the material is missing, because the
     * author did think about a lookup that misses — for the other half.
     */

    /**
     * Deactivating a paper is the likelier of the two roads and the quieter one:
     * it is an ordinary stock-room edit (supplier changed, run finished), the
     * item keeps its `avg_cost`, and nothing about it says «this sheet is now
     * free». The diplomas are still printed on paper either way.
     */
    public function test_a_deactivated_paper_is_still_paid_for_and_still_leaves_the_shelf(): void
    {
        $this->paperA4_160->update(['is_active' => false]);

        $snapshot = $this->service->buildSnapshot(
            $this->diplomaService,
            ['diplomas' => ['qty' => 10]],
            quantity: 1,
        );

        // 10 sheets × 1.50 + 10 color clicks × 0.60 = 21.00
        $this->assertEqualsWithDelta(21.00, $snapshot['total_price_cost'], 0.01);

        $this->assertSame(
            [['inventory_item_id' => $this->paperA4_160->id, 'qty' => 10]],
            $snapshot['inventory_deductions'],
            'ten sheets came off the shelf whether or not the row is offered on a form',
        );
    }

    /**
     * A rename cannot be repaired the same way — there is nothing left to find.
     * What must not happen is the current behaviour: a zero that reads exactly
     * like a legitimately free sheet.
     */
    public function test_a_paper_this_service_cannot_find_is_reported(): void
    {
        $this->paperA4_160->update(['name' => 'Папір А4 160 г/м² (Maestro)']);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'Папір А4 160 г/м²'));

        $this->service->buildSnapshot(
            $this->diplomaService,
            ['diplomas' => ['qty' => 10]],
            quantity: 1,
        );
    }
}
