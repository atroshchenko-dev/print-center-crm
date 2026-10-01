<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\ProcurementAdvisorService;
use Database\Seeders\HardBindingConstructorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The procurement tab and inventory:forecast must count consumption the same
 * way: auto_deduct plus convert_out (cutting a source drains it), convert_in
 * and receipts are stock, not consumption.
 */
class ProcurementAdvisorServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProcurementAdvisorService $advisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->advisor = new ProcurementAdvisorService;

        // A data migration (2026_06_30_170000_add_consumables_category and
        // follow-ups) seeds a dozen cartridges at zero stock under their
        // minimum, so on a migrated database every advise() call would find
        // them in toners.low_full. Deactivate them up front — inside the
        // per-test transaction, so the update rolls back with everything else.
        InventoryItem::query()
            ->whereRelation('category', 'name', InventoryCategory::CONSUMABLES_SLUG)
            ->update(['is_active' => false]);
    }

    private function item(float $stock, array $attributes = []): InventoryItem
    {
        return InventoryItem::factory()->create([
            'inventory_category_id' => InventoryCategory::factory()->create()->id,
            'is_active'             => true,
            'current_quantity'      => $stock,
            ...$attributes,
        ]);
    }

    private function movement(InventoryItem $item, string $type, float $quantity): void
    {
        $this->movementAt($item, $type, $quantity, now());
    }

    /**
     * Movements are append-only (updating created_at throws), so a backdated
     * movement is created with the timestamp set on the instance — created_at
     * is not fillable, mass assignment would drop it.
     */
    private function movementAt(InventoryItem $item, string $type, float $quantity, Carbon $at): void
    {
        $movement = new InventoryMovement([
            'inventory_item_id' => $item->id,
            'type'              => $type,
            'quantity'          => $quantity,
            'user_id'           => User::factory()->create()->id,
        ]);
        $movement->created_at = $at;
        $movement->save();
    }

    // ─── consumptionRates() ──────────────────────────────

    public function test_consumption_sums_deductions_and_cut_outs_only(): void
    {
        $item = $this->item(100);
        $this->movement($item, 'auto_deduct', -30);
        $this->movement($item, 'convert_out', -10);
        $this->movement($item, 'convert_in', 20);
        $this->movement($item, 'in', 500);

        $rates = $this->advisor->consumptionRates(30);

        $this->assertSame(40.0, $rates[$item->id]);
    }

    /**
     * A recount is not a withdrawal.
     *
     * consumptionRates() takes auto_deduct and convert_out; a stocktake writes
     * the difference between the shelf and the books, and that difference can
     * be large — the whole point of counting. Let it through and the forecast
     * reads a one-off correction as a month of demand.
     */
    public function test_a_stocktake_is_not_counted_as_consumption(): void
    {
        $item = $this->item(100);

        $this->movement($item, 'auto_deduct', -30);
        $this->movement($item, 'stocktake', -50);

        $this->assertSame(
            30.0,
            $this->advisor->consumptionRates(30)[$item->id],
            'The stocktake correction was counted as demand.',
        );
    }

    public function test_consumption_outside_the_lookback_window_is_ignored(): void
    {
        $item = $this->item(100);
        $this->movementAt($item, 'auto_deduct', -30, now()->subDays(40));

        $rates = $this->advisor->consumptionRates(30);

        $this->assertFalse($rates->has($item->id));
    }

    // ─── advise(): regular items ─────────────────────────

    /** Helper: named category so advise() can tell special categories apart. */
    private function categoryNamed(string $name): InventoryCategory
    {
        return InventoryCategory::factory()->create(['name' => $name]);
    }

    /** Helper: pull one regular row by item id, or null. */
    private function regularRow(array $advice, int $itemId): ?array
    {
        foreach ($advice['regular'] as $row) {
            if ($row['id'] === $itemId) {
                return $row;
            }
        }

        return null;
    }

    public function test_recommends_enough_to_cover_the_horizon(): void
    {
        // 60 used over 30 days = 2/day; horizon 60 needs 120, stock 100 → buy 20.
        $item = $this->item(100, ['avg_cost' => 1.50]);
        $this->movement($item, 'auto_deduct', -60);

        $row = $this->regularRow($this->advisor->advise(60), $item->id);

        $this->assertNotNull($row);
        $this->assertSame(20, $row['recommended_qty']);
        $this->assertSame(30.00, $row['est_cost']);
        $this->assertSame(50, $row['days_left']);
        $this->assertSame('forecast', $row['reason']);
        $this->assertNull($row['reserve']);
    }

    public function test_recommendation_rounds_up(): void
    {
        // 35.5 used /30d × horizon 30 = 35.5 needed − 10 stock = 25.5 → 26.
        $item = $this->item(10);
        $this->movement($item, 'auto_deduct', -35.5);

        $row = $this->regularRow($this->advisor->advise(30), $item->id);

        $this->assertSame(26, $row['recommended_qty']);
    }

    public function test_a_source_reserve_counts_toward_effective_stock(): void
    {
        // 1 sheet left at 2/day looks like «today» — but 100 source sheets
        // ×2 cover 100 days, the cutter refills it on its own. No row.
        $item = $this->item(1);
        $this->movement($item, 'auto_deduct', -60);
        $source = $this->item(100);
        $item->update(['convertible_from_id' => $source->id, 'conversion_ratio' => 2.00]);

        $this->assertNull($this->regularRow($this->advisor->advise(30), $item->id));
    }

    public function test_a_reserve_too_small_is_counted_and_named(): void
    {
        // Effective 1 + 2×2 = 5; horizon 60 needs 120 → buy 115, reserve named.
        $item = $this->item(1);
        $this->movement($item, 'auto_deduct', -60);
        $source = $this->item(2);
        $item->update(['convertible_from_id' => $source->id, 'conversion_ratio' => 2.00]);

        $row = $this->regularRow($this->advisor->advise(60), $item->id);

        $this->assertSame(115, $row['recommended_qty']);
        $this->assertSame(2, $row['days_left']);
        $this->assertSame(['name' => $source->name, 'qty' => 2.0], $row['reserve']);
    }

    public function test_no_history_falls_back_to_twice_the_minimum(): void
    {
        $item = $this->item(10, ['min_quantity' => 50]);

        $row = $this->regularRow($this->advisor->advise(60), $item->id);

        $this->assertSame(90, $row['recommended_qty']);
        $this->assertSame('min_fallback', $row['reason']);
        $this->assertNull($row['days_left']);
        $this->assertSame(0.0, $row['daily_rate']);
    }

    public function test_quiet_item_above_its_minimum_is_absent(): void
    {
        $item = $this->item(51, ['min_quantity' => 50]);

        $this->assertNull($this->regularRow($this->advisor->advise(60), $item->id));
    }

    public function test_quiet_item_with_zero_minimum_is_absent(): void
    {
        $item = $this->item(0, ['min_quantity' => 0]);

        $this->assertNull($this->regularRow($this->advisor->advise(60), $item->id));
    }

    public function test_zero_avg_cost_yields_null_estimate_not_a_lying_zero(): void
    {
        $item = $this->item(0, ['avg_cost' => 0, 'min_quantity' => 10]);

        $row = $this->regularRow($this->advisor->advise(60), $item->id);

        $this->assertSame(20, $row['recommended_qty']);
        $this->assertNull($row['est_cost']);
    }

    public function test_inactive_items_are_ignored(): void
    {
        $item = $this->item(1, ['is_active' => false]);
        $this->movement($item, 'auto_deduct', -60);

        $this->assertNull($this->regularRow($this->advisor->advise(60), $item->id));
    }

    public function test_special_categories_stay_out_of_the_regular_list(): void
    {
        $toner = InventoryItem::factory()->create([
            'inventory_category_id' => $this->categoryNamed(InventoryCategory::CONSUMABLES_SLUG)->id,
            'current_quantity'      => 0,
            'min_quantity'          => 5,
        ]);
        $channel = InventoryItem::factory()->create([
            'inventory_category_id' => $this->categoryNamed(InventoryCategory::HARD_BINDING_PAIRS_SLUG)->id,
            'name'                  => 'Канал 10мм (Червоний)',
            'current_quantity'      => 0,
            'min_quantity'          => 5,
        ]);

        $advice = $this->advisor->advise(60);

        $this->assertNull($this->regularRow($advice, $toner->id));
        $this->assertNull($this->regularRow($advice, $channel->id));
    }

    public function test_most_urgent_first_fallbacks_last(): void
    {
        $slow = $this->item(100);                       // 2/day → 50 days
        $this->movement($slow, 'auto_deduct', -60);
        $urgent = $this->item(4);                       // 2/day → 2 days
        $this->movement($urgent, 'auto_deduct', -60);
        $fallback = $this->item(1, ['min_quantity' => 10]); // no rate → last

        $ids = array_column($this->advisor->advise(60)['regular'], 'id');

        $this->assertSame([$urgent->id, $slow->id, $fallback->id], $ids);
    }

    public function test_summary_counts_regular_rows_and_criticals(): void
    {
        $urgent = $this->item(4, ['avg_cost' => 2.00]);  // 2 days left → critical
        $this->movement($urgent, 'auto_deduct', -60);

        $advice = $this->advisor->advise(60);

        // needs 120 − 4 = 116 × 2.00 = 232.00
        $this->assertSame(232.00, $advice['summary']['total_cost']);
        $this->assertSame(1, $advice['summary']['items_count']);
        $this->assertSame(1, $advice['summary']['critical_count']);
        $this->assertSame(60, $advice['horizon_days']);
    }

    // ─── advise(): hard-binding pairs ────────────────────

    // Instance property, NOT a static local: statics survive across test
    // methods while RefreshDatabase rolls the row back — the cached id would
    // point at a category that no longer exists. PHPUnit builds a fresh test
    // instance per method, so a property resets correctly.
    private ?InventoryCategory $pairsCategory = null;

    private function pairItem(string $name, float $stock, array $attributes = []): InventoryItem
    {
        $this->pairsCategory ??= $this->categoryNamed(InventoryCategory::HARD_BINDING_PAIRS_SLUG);

        return InventoryItem::factory()->create([
            'inventory_category_id' => $this->pairsCategory->id,
            'name'                  => $name,
            'current_quantity'      => $stock,
            ...$attributes,
        ]);
    }

    private function colorBlock(array $advice, string $color): ?array
    {
        foreach ($advice['pairs']['colors'] as $block) {
            if ($block['color'] === $color) {
                return $block;
            }
        }

        return null;
    }

    public function test_missing_covers_are_recommended_per_color(): void
    {
        $this->pairItem('Канал 10мм (Червоний)', 60);
        $this->pairItem('Канал 16мм (Червоний)', 50);
        $cover = $this->pairItem('Обкладинка тверда (Червоний)', 70, ['avg_cost' => 100]);

        $red = $this->colorBlock($this->advisor->advise(60), 'Червоний');

        $this->assertSame(110.0, $red['channels_total']);
        $this->assertSame(70.0, $red['covers_total']);
        $this->assertSame(70, $red['ready_sets']);
        $this->assertSame(
            ['id' => $cover->id, 'name' => $cover->name, 'qty' => 40, 'est_cost' => 4000.00],
            $red['buy_covers'],
        );
        $this->assertNull($red['channel_deficit']);
    }

    public function test_missing_channels_are_reported_with_size_hints(): void
    {
        $channel = $this->pairItem('Канал 10мм (Синій)', 50);
        $this->movement($channel, 'auto_deduct', -12);
        $this->pairItem('Обкладинка тверда (Синій)', 80);

        $blue = $this->colorBlock($this->advisor->advise(60), 'Синій');

        $this->assertNull($blue['buy_covers']);
        $this->assertSame(30, $blue['channel_deficit']);
        $this->assertSame(50, $blue['ready_sets']);
        $this->assertSame(
            [['id' => $channel->id, 'name' => $channel->name, 'qty' => 50.0, 'used_30d' => 12.0]],
            $blue['channel_sizes'],
        );
    }

    public function test_balanced_color_recommends_nothing(): void
    {
        $this->pairItem('Канал 10мм (Червоний)', 25);
        $this->pairItem('Обкладинка тверда (Червоний)', 25);

        $red = $this->colorBlock($this->advisor->advise(60), 'Червоний');

        $this->assertNull($red['buy_covers']);
        $this->assertNull($red['channel_deficit']);
        $this->assertSame(25, $red['ready_sets']);
    }

    public function test_minimums_do_not_drive_pair_recommendations(): void
    {
        // Below min on both sides but balanced → the phased-out line stays quiet.
        $this->pairItem('Канал 10мм (Червоний)', 3, ['min_quantity' => 5]);
        $this->pairItem('Обкладинка тверда (Червоний)', 3, ['min_quantity' => 5]);

        $advice = $this->advisor->advise(60);
        $red = $this->colorBlock($advice, 'Червоний');

        $this->assertNull($red['buy_covers']);
        $this->assertNull($red['channel_deficit']);
        $this->assertSame(0, $advice['summary']['items_count']);
    }

    public function test_inactive_pair_items_are_not_counted(): void
    {
        $this->pairItem('Канал 10мм (Червоний)', 60);
        $this->pairItem('Канал 16мм (Червоний)', 999, ['is_active' => false]);
        $this->pairItem('Обкладинка тверда (Червоний)', 60);

        $red = $this->colorBlock($this->advisor->advise(60), 'Червоний');

        $this->assertSame(60.0, $red['channels_total']);
        $this->assertNull($red['buy_covers']);
    }

    public function test_emptied_category_disappears(): void
    {
        $this->pairItem('Канал 10мм (Червоний)', 0);
        $this->pairItem('Обкладинка тверда (Червоний)', 0);

        $this->assertTrue($this->advisor->advise(60)['pairs']['all_empty']);
    }

    public function test_cover_recommendation_lands_in_the_summary(): void
    {
        $this->pairItem('Канал 10мм (Червоний)', 10);
        $this->pairItem('Обкладинка тверда (Червоний)', 4, ['avg_cost' => 100]);

        $advice = $this->advisor->advise(60);

        $this->assertSame(600.00, $advice['summary']['total_cost']);
        $this->assertSame(1, $advice['summary']['items_count']);
    }

    /**
     * advisePairs() reads meaning out of item NAMES: «Обкладинка…» is a
     * cover, everything else a channel, «({колір})» picks the block. The
     * names come from HardBindingConstructorSeeder, so run the real seeder —
     * not a copy of its templates — and demand every active item of the
     * pairs category surfaces in some color block. A drifted template or
     * parser would drop items silently: the category keeps them out of the
     * regular rows, so nothing else would ever show them.
     */
    public function test_every_item_the_seeder_creates_surfaces_in_a_color_block(): void
    {
        ServiceCategory::factory()->create(['name' => 'Палітурка тверда']);
        $this->seed(HardBindingConstructorSeeder::class);

        $pairsCategory = InventoryCategory::where('name', InventoryCategory::HARD_BINDING_PAIRS_SLUG)->firstOrFail();
        $seeded = InventoryItem::where('inventory_category_id', $pairsCategory->id)
            ->where('is_active', true)
            ->get();

        // A cover's id surfaces only via buy_covers, which takes a shortage:
        // equal stock everywhere leaves nine channel sizes against one cover
        // per color, so the deficit is guaranteed.
        InventoryItem::whereIn('id', $seeded->pluck('id'))->update(['current_quantity' => 10]);

        $surfaced = collect($this->advisor->advise(60)['pairs']['colors'])->flatMap(fn (array $block) => [
            ...array_column($block['channel_sizes'], 'id'),
            ...($block['buy_covers'] !== null ? [$block['buy_covers']['id']] : []),
        ]);

        $this->assertNotEmpty($seeded);
        $this->assertSame($seeded->pluck('id')->sort()->values()->all(), $surfaced->sort()->values()->all());
    }

    // ─── advise(): toners ────────────────────────────────

    // Instance property for the same RefreshDatabase reason as $pairsCategory.
    private ?InventoryCategory $consumablesCategory = null;

    private function toner(array $attributes): InventoryItem
    {
        $this->consumablesCategory ??= $this->categoryNamed(InventoryCategory::CONSUMABLES_SLUG);

        return InventoryItem::factory()->create([
            'inventory_category_id' => $this->consumablesCategory->id,
            'empty_quantity'        => 0,
            ...$attributes,
        ]);
    }

    public function test_empties_await_refill_with_cost(): void
    {
        $toner = $this->toner(['empty_quantity' => 3, 'refill_cost' => 450, 'current_quantity' => 5, 'min_quantity' => 1]);

        $advice = $this->advisor->advise(60);

        $this->assertSame(
            [['id' => $toner->id, 'name' => $toner->name, 'empty_quantity' => 3.0, 'refill_cost' => 450.0, 'est_cost' => 1350.00]],
            $advice['toners']['refill'],
        );
        $this->assertSame(1350.00, $advice['toners']['refill_total_cost']);
        $this->assertSame(1350.00, $advice['summary']['total_cost']);
        $this->assertSame(1, $advice['summary']['items_count']);
        $this->assertSame([], $advice['toners']['low_full']);
    }

    public function test_unknown_refill_cost_yields_null_estimate(): void
    {
        $this->toner(['empty_quantity' => 2, 'refill_cost' => 0, 'current_quantity' => 5, 'min_quantity' => 1]);

        $advice = $this->advisor->advise(60);

        $this->assertNull($advice['toners']['refill'][0]['est_cost']);
        $this->assertSame(0.00, $advice['toners']['refill_total_cost']);
    }

    public function test_low_full_stock_is_flagged(): void
    {
        $toner = $this->toner(['empty_quantity' => 0, 'current_quantity' => 1, 'min_quantity' => 2]);

        $advice = $this->advisor->advise(60);

        $this->assertSame([], $advice['toners']['refill']);
        $this->assertSame(
            [['id' => $toner->id, 'name' => $toner->name, 'current_quantity' => 1.0, 'empty_quantity' => 0.0, 'min_quantity' => 2.0]],
            $advice['toners']['low_full'],
        );
    }

    public function test_healthy_toner_appears_nowhere(): void
    {
        $this->toner(['empty_quantity' => 0, 'current_quantity' => 5, 'min_quantity' => 2]);

        $advice = $this->advisor->advise(60);

        $this->assertSame([], $advice['toners']['refill']);
        $this->assertSame([], $advice['toners']['low_full']);
    }
}
