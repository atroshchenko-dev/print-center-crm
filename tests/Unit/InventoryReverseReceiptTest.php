<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ServiceParameterOption;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\ProcurementAdvisorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A receipt booked by mistake has to leave the books exactly as it found them.
 *
 * Deducting the quantity is not enough: AVCO issues stock at the running
 * average, so a plain outgoing movement takes the count back down and leaves
 * the average where the wrong receipt put it. The cost that came in with the
 * receipt has to leave with it.
 */
class InventoryReverseReceiptTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new InventoryService;
        $this->user = User::factory()->create(['role' => 'admin']);
    }

    private function item(float $quantity = 0, float $avgCost = 0): InventoryItem
    {
        return InventoryItem::factory()->create([
            'inventory_category_id' => InventoryCategory::factory()->create()->id,
            'name'                  => 'Пружина 8 мм',
            'unit'                  => 'шт',
            'current_quantity'      => $quantity,
            'avg_cost'              => $avgCost,
        ]);
    }

    public function test_a_reversed_receipt_leaves_quantity_and_cost_where_they_were(): void
    {
        $item = $this->item();

        $this->service->receiveStock($item, 100, 200.00, $this->user); // 2.0000 ₴
        $item->refresh();
        $quantityBefore = (float) $item->current_quantity;
        $costBefore = (float) $item->avg_cost;

        $mistake = $this->service->receiveStock($item, 300, 900.00, $this->user); // pulls AVCO to 2.75
        $item->refresh();
        $this->assertEqualsWithDelta(2.75, (float) $item->avg_cost, 0.0001);

        $this->service->reverseReceipt($mistake, $this->user);

        $item->refresh();
        $this->assertEqualsWithDelta($quantityBefore, (float) $item->current_quantity, 0.0001);
        $this->assertEqualsWithDelta($costBefore, (float) $item->avg_cost, 0.0001);
    }

    public function test_the_reversal_is_one_row_pointing_at_the_receipt_it_undoes(): void
    {
        $item = $this->item();
        $receipt = $this->service->receiveStock($item, 300, 723.00, $this->user, 'Прихід від постачальника');

        $reversal = $this->service->reverseReceipt($receipt, $this->user);

        $this->assertSame('reversal', $reversal->type);
        $this->assertEqualsWithDelta(-300, (float) $reversal->quantity, 0.0001);
        $this->assertEqualsWithDelta(723.00, (float) $reversal->total_cost, 0.0001);
        $this->assertSame(InventoryMovement::class, $reversal->reference_type);
        $this->assertSame($receipt->id, $reversal->reference_id);
        $this->assertStringContainsString('Сторно приходу #'.$receipt->id, (string) $reversal->notes);

        $this->assertSame(2, InventoryMovement::where('inventory_item_id', $item->id)->count());
    }

    public function test_an_emptied_item_keeps_the_cost_it_last_knew(): void
    {
        $item = $this->item();
        $receipt = $this->service->receiveStock($item, 300, 723.00, $this->user);

        $this->service->reverseReceipt($receipt, $this->user);

        $item->refresh();
        $this->assertEqualsWithDelta(0, (float) $item->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(2.41, (float) $item->avg_cost, 0.0001);
    }

    public function test_only_a_receipt_can_be_reversed(): void
    {
        $item = $this->item(quantity: 10, avgCost: 2.00);

        $deduction = InventoryMovement::create([
            'inventory_item_id' => $item->id,
            'type'              => 'auto_deduct',
            'quantity'          => -5,
            'unit_cost'         => 2.00,
            'total_cost'        => 10.00,
            'user_id'           => $this->user->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->reverseReceipt($deduction, $this->user);
    }

    public function test_the_same_receipt_cannot_be_reversed_twice(): void
    {
        $item = $this->item();
        $receipt = $this->service->receiveStock($item, 300, 723.00, $this->user);

        $this->service->reverseReceipt($receipt, $this->user);

        $this->expectException(\RuntimeException::class);
        $this->service->reverseReceipt($receipt->fresh(), $this->user);
    }

    public function test_a_receipt_with_movements_after_it_is_refused(): void
    {
        $item = $this->item();
        $receipt = $this->service->receiveStock($item, 300, 723.00, $this->user);

        InventoryMovement::create([
            'inventory_item_id' => $item->id,
            'type'              => 'auto_deduct',
            'quantity'          => -10,
            'unit_cost'         => 2.41,
            'total_cost'        => 24.10,
            'user_id'           => $this->user->id,
        ]);
        $item->update(['current_quantity' => 290]);

        $this->expectException(\RuntimeException::class);
        $this->service->reverseReceipt($receipt, $this->user);
    }

    public function test_force_reverses_past_later_movements(): void
    {
        // 10 on the shelf, then the mistaken 300, then 10 consumed — 300 left.
        // The reversal takes exactly its own 300 back out and lands on zero.
        $item = $this->item();
        $this->service->receiveStock($item, 10, 20.00, $this->user);
        $receipt = $this->service->receiveStock($item, 300, 723.00, $this->user);

        InventoryMovement::create([
            'inventory_item_id' => $item->id,
            'type'              => 'auto_deduct',
            'quantity'          => -10,
            'unit_cost'         => 2.3968,
            'total_cost'        => 23.97,
            'user_id'           => $this->user->id,
        ]);
        $item->update(['current_quantity' => 300]);

        $reversal = $this->service->reverseReceipt($receipt, $this->user, force: true);

        $this->assertSame('reversal', $reversal->type);
        $item->refresh();
        $this->assertEqualsWithDelta(0, (float) $item->current_quantity, 0.0001);
    }

    public function test_a_reversal_that_would_go_negative_is_refused_even_with_force(): void
    {
        $item = $this->item();
        $receipt = $this->service->receiveStock($item, 300, 723.00, $this->user);

        $item->update(['current_quantity' => 12]);

        $this->expectException(\RuntimeException::class);
        $this->service->reverseReceipt($receipt, $this->user, force: true);
    }

    public function test_a_reversal_is_not_consumption(): void
    {
        // `stocktake` is kept out of the consumption list for this exact
        // reason; a correction counted as usage inflates every forecast built
        // on it, and this one is 300 pieces wide.
        $item = $this->item();
        $receipt = $this->service->receiveStock($item, 300, 723.00, $this->user);

        $this->service->reverseReceipt($receipt, $this->user);

        $rates = (new ProcurementAdvisorService)->consumptionRates();

        $this->assertArrayNotHasKey($item->id, $rates->all());
    }

    public function test_the_calculator_option_follows_the_cost_back_down(): void
    {
        $item = $this->item();
        $this->service->receiveStock($item, 100, 200.00, $this->user);

        $option = ServiceParameterOption::factory()->create([
            'inventory_item_id' => $item->id,
            'inventory_qty'     => 1,
            'clicks_per_unit'   => 0,
        ]);
        $this->assertEqualsWithDelta(2.00, (float) $option->fresh()->cost_markup, 0.0001);

        $mistake = $this->service->receiveStock($item, 300, 900.00, $this->user);
        $this->assertEqualsWithDelta(2.75, (float) $option->fresh()->cost_markup, 0.0001);

        $this->service->reverseReceipt($mistake, $this->user);

        $this->assertEqualsWithDelta(2.00, (float) $option->fresh()->cost_markup, 0.0001);
    }

    public function test_a_reversal_is_written_to_the_audit_journal(): void
    {
        $item = $this->item();
        $receipt = $this->service->receiveStock($item, 300, 723.00, $this->user);

        $this->service->reverseReceipt($receipt, $this->user);

        $entry = AuditLog::where('event_type', 'inventory_receipt_reversed')->first();

        $this->assertNotNull($entry, 'A correction nobody can find later is not a correction.');
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertStringContainsString('Пружина 8 мм', (string) $entry->description);
        $this->assertSame($receipt->id, $entry->meta['reversed_movement_id']);
    }

    public function test_replaying_the_journal_reproduces_what_the_service_wrote(): void
    {
        // The journal is the source of truth `inventory:restore-from-movements`
        // rebuilds from. If the two disagreed, running the recovery tool after
        // a reversal would quietly restore the average the reversal removed.
        $item = $this->item();
        $this->service->receiveStock($item, 100, 200.00, $this->user);
        $mistake = $this->service->receiveStock($item, 300, 900.00, $this->user);
        $this->service->reverseReceipt($mistake, $this->user);

        $item->refresh();
        $quantity = (float) $item->current_quantity;
        $avgCost = (float) $item->avg_cost;

        $this->artisan('inventory:restore-from-movements')->assertExitCode(0);

        $item->refresh();
        $this->assertEqualsWithDelta($quantity, (float) $item->current_quantity, 0.0001);
        $this->assertEqualsWithDelta($avgCost, (float) $item->avg_cost, 0.0001);
    }
}
