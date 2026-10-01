<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RestoreInventoryCommandTest
 *
 * `inventory:restore-from-movements` rebuilds current_quantity and avg_cost by
 * replaying the movement journal. avg_cost feeds computeCost() and from there
 * every order price, so a recovery tool that gets it wrong is worse than none.
 */
class RestoreInventoryCommandTest extends TestCase
{
    use RefreshDatabase;

    private InventoryItem $item;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'admin']);
        $this->item = InventoryItem::factory()->create([
            'inventory_category_id' => InventoryCategory::factory()->create()->id,
            'current_quantity'      => 0,
            'avg_cost'              => 0,
        ]);
    }

    private function receive(float $qty, float $unitCost): void
    {
        InventoryMovement::create([
            'inventory_item_id' => $this->item->id,
            'type'              => 'receipt',
            'quantity'          => $qty,
            'unit_cost'         => $unitCost,
            'total_cost'        => $qty * $unitCost,
            'user_id'           => $this->user->id,
            'notes'             => 'test receipt',
        ]);
    }

    private function issue(float $qty, float $unitCost): void
    {
        InventoryMovement::create([
            'inventory_item_id' => $this->item->id,
            'type'              => 'auto_deduct',
            'quantity'          => -$qty,
            'unit_cost'         => $unitCost,
            'total_cost'        => $qty * $unitCost,
            'user_id'           => $this->user->id,
            'notes'             => 'test issue',
        ]);
    }

    private function restore(): void
    {
        $this->artisan('inventory:restore-from-movements')->assertExitCode(0);
        $this->item->refresh();
    }

    public function test_plain_receipts_average_out(): void
    {
        $this->receive(100, 1.00);
        $this->receive(50, 2.00);

        $this->restore();

        // (100 + 100) / 150
        $this->assertEqualsWithDelta(150.0, (float) $this->item->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(1.3333, (float) $this->item->avg_cost, 0.0001);
    }

    public function test_issuing_stock_does_not_move_the_average(): void
    {
        $this->receive(100, 5.00);
        $this->issue(40, 5.00);

        $this->restore();

        $this->assertEqualsWithDelta(60.0, (float) $this->item->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $this->item->avg_cost, 0.0001);
    }

    /**
     * The case the old guard missed: emptying to exactly zero left the item's
     * whole value in the pool, and the next receipt averaged against it.
     */
    public function test_restocking_after_an_item_hits_exactly_zero_keeps_the_real_cost(): void
    {
        $this->receive(10, 5.00);
        $this->issue(10, 5.00);   // stock is now exactly 0
        $this->receive(10, 5.00);

        $this->restore();

        $this->assertEqualsWithDelta(10.0, (float) $this->item->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(
            5.0,
            (float) $this->item->avg_cost,
            0.0001,
            'Bought at 5 ₴ twice with a zero in between — the average is still 5 ₴.',
        );
    }

    public function test_an_item_left_empty_is_worth_nothing(): void
    {
        $this->receive(10, 5.00);
        $this->issue(10, 5.00);

        $this->restore();

        $this->assertEqualsWithDelta(0.0, (float) $this->item->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $this->item->avg_cost, 0.0001);
    }

    public function test_restock_at_a_new_price_averages_against_what_is_left(): void
    {
        $this->receive(100, 1.00);
        $this->issue(50, 1.00);    // 50 left, worth 50
        $this->receive(50, 3.00);  // + 150

        $this->restore();

        // (50 + 150) / 100
        $this->assertEqualsWithDelta(100.0, (float) $this->item->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $this->item->avg_cost, 0.0001);
    }

    public function test_over_issue_does_not_leave_a_negative_valuation(): void
    {
        $this->receive(10, 5.00);
        $this->issue(25, 5.00);   // more than there ever was

        $this->restore();

        $this->assertEqualsWithDelta(0.0, (float) $this->item->current_quantity, 0.0001);
        $this->assertGreaterThanOrEqual(0.0, (float) $this->item->avg_cost);
    }

    public function test_items_with_no_movements_are_left_alone(): void
    {
        $untouched = InventoryItem::factory()->create([
            'inventory_category_id' => $this->item->inventory_category_id,
            'current_quantity'      => 42,
            'avg_cost'              => 7.00,
        ]);

        $this->receive(10, 5.00);
        $this->restore();

        $untouched->refresh();
        $this->assertEqualsWithDelta(42.0, (float) $untouched->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(7.0, (float) $untouched->avg_cost, 0.0001);
    }

    public function test_a_reversal_takes_back_the_cost_its_receipt_brought(): void
    {
        // 100 @ 2.00, then a mistaken 300 @ 3.00, then its reversal. Replaying
        // the journal has to land where the item stood before the mistake —
        // 100 pieces at 2.00 — and not at the average the mistake created. The
        // proportional branch below would do the latter: it removes value at
        // the running average, which is right for a withdrawal and wrong for a
        // receipt that never happened.
        $this->receive(100, 2.00);
        $this->receive(300, 3.00);

        $mistake = InventoryMovement::where('inventory_item_id', $this->item->id)
            ->orderByDesc('id')
            ->first();

        InventoryMovement::create([
            'inventory_item_id' => $this->item->id,
            'type'              => 'reversal',
            'quantity'          => -300,
            'unit_cost'         => 3.00,
            'total_cost'        => 900.00,
            'reference_type'    => InventoryMovement::class,
            'reference_id'      => $mistake->id,
            'user_id'           => $this->user->id,
        ]);

        $this->restore();

        $this->assertEqualsWithDelta(100.0, (float) $this->item->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(2.00, (float) $this->item->avg_cost, 0.0001);
    }
}
