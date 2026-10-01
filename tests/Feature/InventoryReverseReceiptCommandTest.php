<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The command is the part the owner touches, on production, once.
 *
 * Everything it refuses it must refuse loudly and before writing: a name that
 * matches two things, a name that matches nothing, an item that never had a
 * receipt. --dry-run exists so the arithmetic can be read before it is
 * believed.
 */
class InventoryReverseReceiptCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function item(string $name): InventoryItem
    {
        return InventoryItem::factory()->create([
            'inventory_category_id' => InventoryCategory::factory()->create()->id,
            'name'                  => $name,
            'unit'                  => 'шт',
            'current_quantity'      => 0,
            'avg_cost'              => 0,
            'is_active'             => true,
        ]);
    }

    public function test_a_dry_run_shows_the_arithmetic_and_writes_nothing(): void
    {
        $item = $this->item('Пружина 8 мм');
        (new InventoryService)->receiveStock($item, 300, 723.00, $this->admin);

        $this->artisan('inventory:reverse-receipt', ['item' => 'ружина 8', '--dry-run' => true])
            ->assertExitCode(0);

        $item->refresh();
        $this->assertEqualsWithDelta(300, (float) $item->current_quantity, 0.0001);
        $this->assertSame(1, InventoryMovement::where('inventory_item_id', $item->id)->count());
    }

    public function test_two_matching_names_stop_the_command(): void
    {
        $first = $this->item('Пружина 8 мм');
        $this->item('Пружина 8 мм чорна');
        (new InventoryService)->receiveStock($first, 300, 723.00, $this->admin);

        $this->artisan('inventory:reverse-receipt', ['item' => 'Пружина 8'])
            ->assertExitCode(1);

        $first->refresh();
        $this->assertEqualsWithDelta(300, (float) $first->current_quantity, 0.0001);
    }

    public function test_a_name_that_matches_nothing_stops_the_command(): void
    {
        $this->artisan('inventory:reverse-receipt', ['item' => 'дирижабль'])
            ->assertExitCode(1);
    }

    public function test_an_item_that_never_had_a_receipt_stops_the_command(): void
    {
        $this->item('Пружина 8 мм');

        $this->artisan('inventory:reverse-receipt', ['item' => 'Пружина 8'])
            ->assertExitCode(1);
    }

    public function test_a_real_run_reverses_the_last_receipt(): void
    {
        $item = $this->item('Пружина 8 мм');
        $service = new InventoryService;
        $service->receiveStock($item, 100, 200.00, $this->admin);
        $service->receiveStock($item, 300, 900.00, $this->admin);

        $this->artisan('inventory:reverse-receipt', ['item' => 'ружина 8'])
            ->assertExitCode(0);

        $item->refresh();
        $this->assertEqualsWithDelta(100, (float) $item->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(2.00, (float) $item->avg_cost, 0.0001);
        $this->assertSame(1, InventoryMovement::where('inventory_item_id', $item->id)
            ->where('type', 'reversal')->count());
    }
}
