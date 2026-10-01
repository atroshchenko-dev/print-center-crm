<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * InventoryDeductionTest — stock coming off an order, and coming back.
 *
 * InventoryServiceTest covered receipts, returns and movement immutability,
 * leaving deductStock() and the A3→A4 auto-conversion — the most intricate
 * logic in the project — at 0%. Both touch real materials and cost price, so
 * the invariants are pinned here:
 *
 *  - stock never goes negative, and a shortfall is recorded rather than hidden
 *  - the movement journal states what was actually taken, not what was asked for
 *  - A3 is cut into A4 only when needed, and only as much as needed
 *  - cancelling an order returns what the journal says was taken
 */
class InventoryDeductionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private InventoryCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->category = InventoryCategory::factory()->create();
    }

    private function item(array $attributes = []): InventoryItem
    {
        return InventoryItem::create(array_merge([
            'inventory_category_id' => $this->category->id,
            'name'                  => 'Папір '.uniqid(),
            'unit'                  => 'арк',
            'current_quantity'      => 0,
            'empty_quantity'        => 0,
            'avg_cost'              => 0,
            'min_quantity'          => 0,
            'is_active'             => true,
        ], $attributes));
    }

    /** An order whose single item consumes $qtyPerUnit of $item per unit. */
    private function orderConsuming(InventoryItem $item, float $qtyPerUnit, int $quantity = 1): Order
    {
        $order = Order::factory()->create();

        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'quantity'         => $quantity,
            'service_snapshot' => [
                'service_type'         => 'constructor',
                'constructor_snapshot' => [[
                    'option_id'         => 1,
                    'group_name'        => 'Тип паперу',
                    'option_name'       => $item->name,
                    'inventory_item_id' => $item->id,
                    'inventory_qty'     => $qtyPerUnit,
                ]],
            ],
        ]);

        return $order->load('items');
    }

    // ─── Ordinary deduction ──────────────────────────────

    public function test_stock_is_deducted_and_journalled(): void
    {
        $paper = $this->item(['current_quantity' => 100, 'avg_cost' => 2.00]);
        $order = $this->orderConsuming($paper, 1, quantity: 10);

        app(InventoryService::class)->autoDeductForOrder($order, $this->user);

        $this->assertEqualsWithDelta(90.0, (float) $paper->fresh()->current_quantity, 0.001);

        $movement = InventoryMovement::where('inventory_item_id', $paper->id)
            ->where('type', 'auto_deduct')
            ->first();

        $this->assertNotNull($movement);
        $this->assertEqualsWithDelta(-10.0, (float) $movement->quantity, 0.001);
    }

    // ─── Shortfall ───────────────────────────────────────

    public function test_a_shortfall_clamps_instead_of_going_negative(): void
    {
        $paper = $this->item(['current_quantity' => 3, 'avg_cost' => 2.00]);
        $order = $this->orderConsuming($paper, 1, quantity: 10);

        app(InventoryService::class)->autoDeductForOrder($order, $this->user);

        $this->assertEqualsWithDelta(
            0.0,
            (float) $paper->fresh()->current_quantity,
            0.001,
            'Stock must bottom out at zero, never go negative.',
        );
    }

    public function test_the_journal_records_what_was_actually_taken(): void
    {
        $paper = $this->item(['current_quantity' => 3, 'avg_cost' => 2.00]);
        $order = $this->orderConsuming($paper, 1, quantity: 10);

        app(InventoryService::class)->autoDeductForOrder($order, $this->user);

        $movement = InventoryMovement::where('inventory_item_id', $paper->id)
            ->where('type', 'auto_deduct')
            ->first();

        $this->assertEqualsWithDelta(
            -3.0,
            (float) $movement->quantity,
            0.001,
            'The movement must state the clamped amount, not the requested one.',
        );
        $this->assertStringContainsString('дефіцит', (string) $movement->notes);
    }

    /**
     * The audit trail below is the record; this is the part the operator sees.
     * Before finding L-4 the shortfall existed only in the log, so a job could
     * be handed over with the shelf silently emptied.
     */
    public function test_a_shortfall_is_reported_back_to_the_caller(): void
    {
        $paper = $this->item(['name' => 'Папір A4 80г', 'current_quantity' => 3]);
        $order = $this->orderConsuming($paper, 1, quantity: 10);

        $result = app(InventoryService::class)->autoDeductForOrder($order, $this->user);

        $this->assertCount(1, $result['deficits']);
        $this->assertStringContainsString('Папір A4 80г', $result['deficits'][0]);
        $this->assertStringContainsString('10', $result['deficits'][0]);
        $this->assertStringContainsString('3', $result['deficits'][0]);
    }

    public function test_no_shortfall_reports_nothing(): void
    {
        $paper = $this->item(['current_quantity' => 100]);
        $order = $this->orderConsuming($paper, 1, quantity: 10);

        $result = app(InventoryService::class)->autoDeductForOrder($order, $this->user);

        $this->assertSame([], $result['deficits']);
    }

    public function test_a_shortfall_is_audited(): void
    {
        $paper = $this->item(['current_quantity' => 3]);
        $order = $this->orderConsuming($paper, 1, quantity: 10);

        app(InventoryService::class)->autoDeductForOrder($order, $this->user);

        $entry = AuditLog::where('event_type', 'inventory_deficit')->first();

        $this->assertNotNull($entry, 'A shortfall must leave an audit trail.');
        $this->assertSame($paper->id, $entry->meta['inventory_item_id']);
        $this->assertEqualsWithDelta(10.0, (float) $entry->meta['requested'], 0.001);
    }

    // ─── A3 → A4 auto-conversion ─────────────────────────

    private function convertiblePair(float $a3Qty, float $a3Cost, float $a4Qty = 0): array
    {
        $a3 = $this->item([
            'name'             => 'Папір А3 80г',
            'current_quantity' => $a3Qty,
            'avg_cost'         => $a3Cost,
        ]);
        $a4 = $this->item([
            'name'                => 'Папір А4 80г',
            'current_quantity'    => $a4Qty,
            'avg_cost'            => 0,
            'convertible_from_id' => $a3->id,
            'conversion_ratio'    => 2.0,
        ]);

        return [$a3, $a4];
    }

    public function test_a3_is_cut_into_a4_when_a4_runs_short(): void
    {
        [$a3, $a4] = $this->convertiblePair(a3Qty: 50, a3Cost: 2.00, a4Qty: 4);
        $order = $this->orderConsuming($a4, 1, quantity: 10);

        $conversions = app(InventoryService::class)->autoDeductForOrder($order, $this->user)['conversions'];

        $this->assertNotEmpty($conversions, 'The operator must be told a sheet was cut.');

        // Deficit 6 sheets → ceil(6 / 2) = 3 sheets of A3 → 6 of A4.
        $this->assertEqualsWithDelta(47.0, (float) $a3->fresh()->current_quantity, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $a4->fresh()->current_quantity, 0.001);

        $this->assertDatabaseHas('inventory_movements', ['inventory_item_id' => $a3->id, 'type' => 'convert_out']);
        $this->assertDatabaseHas('inventory_movements', ['inventory_item_id' => $a4->id, 'type' => 'convert_in']);
    }

    public function test_cutting_halves_the_unit_cost(): void
    {
        [, $a4] = $this->convertiblePair(a3Qty: 50, a3Cost: 3.00, a4Qty: 0);
        $order = $this->orderConsuming($a4, 1, quantity: 2);

        app(InventoryService::class)->autoDeductForOrder($order, $this->user);

        $convertIn = InventoryMovement::where('inventory_item_id', $a4->id)
            ->where('type', 'convert_in')
            ->first();

        $this->assertEqualsWithDelta(
            1.50,
            (float) $convertIn->unit_cost,
            0.001,
            'A4 produced from a 3.00 A3 sheet at ratio 2 must cost 1.50.',
        );
    }

    public function test_only_the_shortfall_is_cut(): void
    {
        [$a3, $a4] = $this->convertiblePair(a3Qty: 100, a3Cost: 2.00, a4Qty: 0);
        $order = $this->orderConsuming($a4, 1, quantity: 2);

        app(InventoryService::class)->autoDeductForOrder($order, $this->user);

        $this->assertEqualsWithDelta(
            99.0,
            (float) $a3->fresh()->current_quantity,
            0.001,
            'Only one A3 sheet is needed for two A4 — the rest of the stock stays intact.',
        );
    }

    public function test_no_cutting_happens_while_a4_suffices(): void
    {
        [$a3, $a4] = $this->convertiblePair(a3Qty: 50, a3Cost: 2.00, a4Qty: 100);
        $order = $this->orderConsuming($a4, 1, quantity: 10);

        $conversions = app(InventoryService::class)->autoDeductForOrder($order, $this->user)['conversions'];

        $this->assertSame([], $conversions);
        $this->assertEqualsWithDelta(50.0, (float) $a3->fresh()->current_quantity, 0.001);
    }

    public function test_cutting_is_audited(): void
    {
        [, $a4] = $this->convertiblePair(a3Qty: 50, a3Cost: 2.00, a4Qty: 0);
        $order = $this->orderConsuming($a4, 1, quantity: 4);

        app(InventoryService::class)->autoDeductForOrder($order, $this->user);

        $this->assertDatabaseHas('audit_logs', ['event_type' => 'paper_auto_conversion']);
    }

    // ─── Returning stock ─────────────────────────────────

    public function test_cancelling_returns_only_what_was_really_taken(): void
    {
        // Asked for 10, only 3 on hand — a naive return would invent 7 sheets.
        $paper = $this->item(['current_quantity' => 3, 'avg_cost' => 2.00]);
        $order = $this->orderConsuming($paper, 1, quantity: 10);

        $service = app(InventoryService::class);
        $service->autoDeductForOrder($order, $this->user);
        $service->returnStockForOrder($order, $this->user);

        $this->assertEqualsWithDelta(
            3.0,
            (float) $paper->fresh()->current_quantity,
            0.001,
            'Returning must restore the journalled amount, not the requested one.',
        );
    }

    public function test_a_full_cycle_leaves_stock_where_it_started(): void
    {
        $paper = $this->item(['current_quantity' => 100, 'avg_cost' => 2.00]);
        $order = $this->orderConsuming($paper, 2, quantity: 5);

        $service = app(InventoryService::class);
        $service->autoDeductForOrder($order, $this->user);
        $this->assertEqualsWithDelta(90.0, (float) $paper->fresh()->current_quantity, 0.001);

        $service->returnStockForOrder($order, $this->user);
        $this->assertEqualsWithDelta(100.0, (float) $paper->fresh()->current_quantity, 0.001);
    }

    /**
     * One order can take the same item twice — two positions on the same
     * paper. Each return looked the cost up again with ->first() over the
     * order+item pair instead of using the movement it was handed, so both
     * came back at whichever price the database happened to hand over first.
     *
     * The two prices differ whenever an auto-conversion re-averaged the item
     * between the deductions, which is exactly when the second deduction
     * happens at all.
     */
    public function test_each_return_carries_the_cost_of_its_own_deduction(): void
    {
        $paper = $this->item(['current_quantity' => 100, 'avg_cost' => 4.00]);
        $order = Order::factory()->create();

        // Two positions, same paper. Between them the item is re-priced, the
        // way a conversion or a receipt would do it mid-order.
        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'quantity'         => 10,
            'service_snapshot' => ['constructor_snapshot' => [[
                'inventory_item_id' => $paper->id, 'inventory_qty' => 1,
            ]]],
        ]);

        $service = app(InventoryService::class);
        $service->autoDeductForOrder($order->load('items'), $this->user);

        $paper->update(['avg_cost' => 1.00]);

        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'quantity'         => 10,
            'service_snapshot' => ['constructor_snapshot' => [[
                'inventory_item_id' => $paper->id, 'inventory_qty' => 1,
            ]]],
        ]);
        $order->unsetRelation('items');

        // Deduct only the second position, so the two movements differ in price.
        $second = $order->items()->latest('id')->first();
        $onlySecond = clone $order;
        $onlySecond->setRelation('items', collect([$second]));
        $service->autoDeductForOrder($onlySecond, $this->user);

        $costs = InventoryMovement::where('inventory_item_id', $paper->id)
            ->where('type', 'auto_deduct')
            ->orderBy('id')
            ->pluck('unit_cost')
            ->map(fn ($c) => (float) $c)
            ->all();
        $this->assertEqualsWithDelta([4.00, 1.00], $costs, 0.001, 'Precondition: two deductions at two prices.');

        // 80 sheets left, worth 80 × 1.00 after the re-price.
        $paper->refresh();
        $valueBefore = (float) $paper->current_quantity * (float) $paper->avg_cost;

        $service->returnStockForOrder($order->fresh()->load('items'), $this->user);

        $returned = InventoryMovement::where('inventory_item_id', $paper->id)
            ->where('type', 'return')
            ->orderBy('id')
            ->pluck('total_cost')
            ->map(fn ($c) => (float) $c)
            ->all();

        $this->assertEqualsWithDelta(
            [40.00, 10.00],
            $returned,
            0.001,
            'Both returns were valued at the first deduction\'s price.',
        );

        $paper->refresh();
        $this->assertEqualsWithDelta(
            $valueBefore + 50.00,
            (float) $paper->current_quantity * (float) $paper->avg_cost,
            0.01,
            'Returning must put back exactly the value that was taken out.',
        );
    }

    /**
     * convertStock() is public. At ratio 0 the source is written off and the
     * target receives nothing: the value simply disappears. Both of today's
     * callers happen to guard it — deductStock() by condition, the admin form
     * by validation — so the guard belongs where the damage is done.
     */
    public function test_a_conversion_with_a_zero_ratio_is_refused(): void
    {
        $a3 = $this->item(['current_quantity' => 100, 'avg_cost' => 5.00]);
        $a4 = $this->item(['current_quantity' => 0,   'avg_cost' => 0]);

        $this->expectException(\InvalidArgumentException::class);

        app(InventoryService::class)->convertStock(
            source: $a3, target: $a4, sourceQuantity: 10, ratio: 0, user: $this->user,
        );
    }

    public function test_a_refused_conversion_leaves_the_source_untouched(): void
    {
        $a3 = $this->item(['current_quantity' => 100, 'avg_cost' => 5.00]);
        $a4 = $this->item(['current_quantity' => 0,   'avg_cost' => 0]);

        try {
            app(InventoryService::class)->convertStock(
                source: $a3, target: $a4, sourceQuantity: 10, ratio: 0, user: $this->user,
            );
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertEqualsWithDelta(100.0, (float) $a3->fresh()->current_quantity, 0.001);
        $this->assertSame(0, InventoryMovement::where('type', 'convert_out')->count());
    }

    // ─── Toner lifecycle ─────────────────────────────────

    public function test_installing_a_toner_moves_it_to_the_empty_pile(): void
    {
        $toner = $this->item(['current_quantity' => 5, 'empty_quantity' => 0, 'avg_cost' => 500.00]);

        app(InventoryService::class)->installToner($toner, 2, $this->user);

        $fresh = $toner->fresh();
        $this->assertEqualsWithDelta(3.0, (float) $fresh->current_quantity, 0.001);
        $this->assertEqualsWithDelta(2.0, (float) $fresh->empty_quantity, 0.001);
    }

    public function test_installing_more_toners_than_exist_is_refused(): void
    {
        $toner = $this->item(['current_quantity' => 1]);

        $this->expectException(\InvalidArgumentException::class);

        app(InventoryService::class)->installToner($toner, 5, $this->user);
    }

    public function test_refilling_returns_toners_to_stock_and_reprices_them(): void
    {
        $toner = $this->item(['current_quantity' => 1, 'empty_quantity' => 3, 'avg_cost' => 100.00]);

        // Two refills at 50.00 each on top of one cartridge worth 100.00
        // → (1×100 + 100) / 3 = 66.6667
        app(InventoryService::class)->refillToner($toner, 2, 100.00, $this->user);

        $fresh = $toner->fresh();
        $this->assertEqualsWithDelta(3.0, (float) $fresh->current_quantity, 0.001);
        $this->assertEqualsWithDelta(1.0, (float) $fresh->empty_quantity, 0.001);
        $this->assertEqualsWithDelta(66.6667, (float) $fresh->avg_cost, 0.001);
    }

    public function test_refilling_more_than_are_empty_is_refused(): void
    {
        $toner = $this->item(['current_quantity' => 0, 'empty_quantity' => 1]);

        $this->expectException(\InvalidArgumentException::class);

        app(InventoryService::class)->refillToner($toner, 4, 200.00, $this->user);
    }

    // ─── The low-stock signal fires on crossing ──────────
    //
    // The condition was checked after every deduction rather than on crossing
    // the minimum, so an item already below it pinged the chat again on every
    // order that touched it. Measured on production 2026-08-04: 74 active items
    // at or below their minimum, 23 deductions on them in 30 days — all from
    // three items, roughly eight messages each, every one repeating the last.
    // R8-4 is the rule being broken: a monitor that is always red is one nobody
    // reads, and the fourth, real warning is scrolled past with the rest.

    /** Route Telegram into the fake and make sure the toggle is on. */
    private function watchTelegram(): void
    {
        Config::set('services.telegram.bot_token', 'test-token-123');
        Config::set('services.telegram.chat_id', '12345678');

        // The settings cache outlives a rolled-back transaction.
        Cache::forget('app:settings');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    /** How many "низький залишок" messages actually left. */
    private function lowStockMessages(): int
    {
        $count = 0;

        Http::recorded(function ($request) use (&$count) {
            if (str_contains($request->url(), 'api.telegram.org')
                && str_contains((string) ($request['text'] ?? ''), 'Низький залишок')) {
                $count++;
            }

            return true;
        });

        return $count;
    }

    public function test_falling_through_the_minimum_is_announced(): void
    {
        $this->watchTelegram();

        $paper = $this->item(['name' => 'Папір А4 80г', 'current_quantity' => 100, 'min_quantity' => 50]);
        $order = $this->orderConsuming($paper, 1, quantity: 60);

        app(InventoryService::class)->autoDeductForOrder($order, $this->user);

        $this->assertEqualsWithDelta(40.0, (float) $paper->fresh()->current_quantity, 0.001);
        $this->assertSame(1, $this->lowStockMessages(), 'Crossing the minimum must be reported.');

        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'Папір А4 80г'));
    }

    public function test_a_deduction_that_stays_below_the_minimum_is_silent(): void
    {
        $this->watchTelegram();

        // Already under the minimum before this order, and still under it after.
        $paper = $this->item(['current_quantity' => 40, 'min_quantity' => 50]);
        $order = $this->orderConsuming($paper, 1, quantity: 10);

        app(InventoryService::class)->autoDeductForOrder($order, $this->user);

        $this->assertSame(
            0,
            $this->lowStockMessages(),
            'An item that was already below its minimum must not repeat the warning.',
        );
    }

    /**
     * The production shape, in one test: one item, eight orders, of which the
     * first takes it under the minimum. That used to be eight messages saying
     * the same thing.
     */
    public function test_eight_orders_on_a_depleted_item_produce_one_message(): void
    {
        $this->watchTelegram();

        $paper = $this->item(['current_quantity' => 100, 'min_quantity' => 50]);
        $service = app(InventoryService::class);

        foreach ([55, 5, 5, 5, 5, 5, 5, 5] as $qty) {
            $service->autoDeductForOrder($this->orderConsuming($paper, 1, quantity: $qty), $this->user);
        }

        $this->assertEqualsWithDelta(10.0, (float) $paper->fresh()->current_quantity, 0.001);
        $this->assertSame(1, $this->lowStockMessages(), 'Eight deductions below the minimum are one crossing.');
    }

    /** A receipt that lifts stock back over the minimum arms the signal again. */
    public function test_restocking_above_the_minimum_arms_the_signal_again(): void
    {
        $this->watchTelegram();

        $paper = $this->item(['current_quantity' => 100, 'min_quantity' => 50, 'avg_cost' => 2.00]);
        $service = app(InventoryService::class);

        $service->autoDeductForOrder($this->orderConsuming($paper, 1, quantity: 60), $this->user); // 100 → 40: crossing
        $service->autoDeductForOrder($this->orderConsuming($paper, 1, quantity: 10), $this->user); // 40 → 30: silent

        $service->receiveStock($paper->fresh(), 100, 200.00, $this->user);                          // 30 → 130

        $service->autoDeductForOrder($this->orderConsuming($paper, 1, quantity: 90), $this->user);  // 130 → 40: crossing

        $this->assertSame(2, $this->lowStockMessages(), 'Each fall through the minimum is its own warning.');
    }

    /** A minimum of zero means "do not watch this item" — it was never a threshold. */
    public function test_an_item_without_a_minimum_is_never_announced(): void
    {
        $this->watchTelegram();

        $paper = $this->item(['current_quantity' => 100, 'min_quantity' => 0]);
        $order = $this->orderConsuming($paper, 1, quantity: 100);

        app(InventoryService::class)->autoDeductForOrder($order, $this->user);

        $this->assertSame(0, $this->lowStockMessages());
    }
}
