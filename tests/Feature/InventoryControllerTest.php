<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Service;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The warehouse screen: nine endpoints, 19.7% covered and unmoved for four
 * rounds. Every one of them writes stock or cost, and the linked constructor
 * options recompute from what they write.
 */
class InventoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private InventoryCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role'        => 'admin',
            'permissions' => User::permissionKeys(),
        ]);

        $this->category = InventoryCategory::factory()->create(['name' => 'Папір']);
    }

    private function item(array $attributes = []): InventoryItem
    {
        return InventoryItem::factory()->create([
            'inventory_category_id' => $this->category->id,
            ...$attributes,
        ]);
    }

    // ─── Creating an item ────────────────────────────────

    /**
     * store() writes current_quantity = 0 and avg_cost = 0 over whatever
     * arrives: a new item's stock comes in through Оприбуткування, which leaves
     * a movement behind, and its cost is AVCO from that receipt. The form used
     * to take both as required numbers on the toner branch and report success.
     * This is the half that made the form wrong.
     */
    public function test_a_new_item_starts_empty_whatever_the_form_sent(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.inventory.store'), [
                'inventory_category_id' => $this->category->id,
                'name'                  => 'Тонер Ricoh',
                'unit'                  => 'шт',
                'min_quantity'          => 2,
                'current_quantity'      => 7,
                'avg_cost'              => 480.50,
                'empty_quantity'        => 3,
                'refill_cost'           => 250,
            ])
            ->assertRedirect();

        $item = InventoryItem::where('name', 'Тонер Ricoh')->sole();

        $this->assertSame('0.0000', (string) $item->avg_cost);
        $this->assertSame('0.0000', (string) $item->current_quantity);

        // These two are not overwritten, and the form is right to ask for them.
        $this->assertSame('3.0000', (string) $item->empty_quantity);
        $this->assertSame('250.0000', (string) $item->refill_cost);
    }

    public function test_a_new_item_goes_to_the_end_of_its_own_category(): void
    {
        $other = InventoryCategory::factory()->create(['name' => 'Тонери']);
        $this->item(['sort_order' => 5]);
        InventoryItem::factory()->create(['inventory_category_id' => $other->id, 'sort_order' => 99]);

        $this->actingAs($this->admin)->post(route('admin.inventory.store'), [
            'inventory_category_id' => $this->category->id,
            'name'                  => 'Папір А4 80',
            'unit'                  => 'арк',
            'min_quantity'          => 100,
        ]);

        $this->assertSame(6, InventoryItem::where('name', 'Папір А4 80')->sole()->sort_order);
    }

    // ─── Editing an item ─────────────────────────────────

    /**
     * A manual cost correction has to reach the constructor options priced off
     * this stock, or the screen shows one number and the calculator uses another.
     */
    public function test_editing_the_cost_recomputes_the_options_priced_off_it(): void
    {
        $item = $this->item(['avg_cost' => 2.00, 'current_quantity' => 100]);
        $option = $this->optionLinkedTo($item, qty: 3);

        $before = (float) $option->fresh()->cost_markup;

        $this->actingAs($this->admin)
            ->patch(route('admin.inventory.update', $item), [
                'inventory_category_id' => $this->category->id,
                'name'                  => $item->name,
                'unit'                  => $item->unit,
                'min_quantity'          => 10,
                'avg_cost'              => 5.00,
            ])
            ->assertRedirect();

        $this->assertSame('5.0000', (string) $item->fresh()->avg_cost);
        $this->assertNotEquals($before, (float) $option->fresh()->cost_markup);
        $this->assertEqualsWithDelta(15.00, (float) $option->fresh()->cost_markup, 0.0001);
    }

    /**
     * The edit modal loads every «0» money field as null (parseFloat(x) || null
     * since the toner-modal round), and those columns are NOT NULL DEFAULT 0 —
     * so saving any plain paper item 500-ed on refill_cost in production
     * (SQLSTATE 23502, 2026-08-12). A null in a zeroable field must land as 0,
     * but only for keys the form actually sent: inventing an absent
     * current_quantity here would zero real stock on every edit.
     */
    public function test_blanked_zeroable_fields_save_as_zero_not_as_a_500(): void
    {
        $item = $this->item(['refill_cost' => 0, 'avg_cost' => 12.50, 'current_quantity' => 14]);

        $this->actingAs($this->admin)
            ->patch(route('admin.inventory.update', $item), [
                'inventory_category_id' => $this->category->id,
                'name'                  => $item->name,
                'unit'                  => $item->unit,
                'min_quantity'          => 5,
                'avg_cost'              => null,
                'refill_cost'           => null,
                'empty_quantity'        => null,
            ])
            ->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame('0.0000', (string) $fresh->refill_cost);
        $this->assertSame('0.0000', (string) $fresh->avg_cost);
        $this->assertSame('0.0000', (string) $fresh->empty_quantity);
        // current_quantity was not in the payload — stock survives untouched.
        $this->assertSame('14.0000', (string) $fresh->current_quantity);
    }

    // ─── Receipt ─────────────────────────────────────────

    /**
     * The form is filled in packs; the warehouse is kept in units. Both the
     * quantity and the AVCO depend on that multiplication being done once and
     * in the right direction.
     */
    public function test_a_receipt_converts_packs_to_units_and_averages_the_cost(): void
    {
        $item = $this->item(['current_quantity' => 0, 'avg_cost' => 0]);

        $this->actingAs($this->admin)
            ->post(route('admin.inventory.receipt'), [
                'inventory_item_id'  => $item->id,
                'packs_quantity'     => 5,
                'units_per_pack'     => 500,
                'price_per_pack'     => 250,
                'auto_update_markup' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $item->refresh();

        $this->assertSame('2500.0000', (string) $item->current_quantity);   // 5 × 500
        $this->assertEqualsWithDelta(0.50, (float) $item->avg_cost, 0.0001); // 1250 / 2500
    }

    public function test_a_receipt_can_refresh_the_options_priced_off_the_stock(): void
    {
        $item = $this->item(['current_quantity' => 0, 'avg_cost' => 0]);
        $option = $this->optionLinkedTo($item, qty: 2);

        $this->actingAs($this->admin)->post(route('admin.inventory.receipt'), [
            'inventory_item_id'  => $item->id,
            'packs_quantity'     => 1,
            'units_per_pack'     => 100,
            'price_per_pack'     => 100,
            'auto_update_markup' => true,
        ]);

        // 100 ₴ / 100 units = 1,00 per unit × 2 units per service unit
        $this->assertEqualsWithDelta(2.00, (float) $option->fresh()->cost_markup, 0.0001);
    }

    // ─── Conversion ──────────────────────────────────────

    public function test_a_conversion_moves_stock_across_at_the_ratio(): void
    {
        $source = $this->item(['name' => 'Папір А3', 'current_quantity' => 100, 'avg_cost' => 2.00]);
        $target = $this->item(['name' => 'Папір А4', 'current_quantity' => 0,   'avg_cost' => 0]);

        $this->actingAs($this->admin)
            ->post(route('admin.inventory.convert'), [
                'source_item_id' => $source->id,
                'target_item_id' => $target->id,
                'quantity'       => 10,
                'ratio'          => 2,
            ])
            ->assertSessionHas('success');

        $this->assertSame('90.0000', (string) $source->fresh()->current_quantity);
        $this->assertSame('20.0000', (string) $target->fresh()->current_quantity);
    }

    public function test_a_conversion_beyond_the_stock_is_refused_with_a_message(): void
    {
        $source = $this->item(['current_quantity' => 5]);
        $target = $this->item(['current_quantity' => 0]);

        $this->actingAs($this->admin)
            ->post(route('admin.inventory.convert'), [
                'source_item_id' => $source->id,
                'target_item_id' => $target->id,
                'quantity'       => 50,
                'ratio'          => 2,
            ])
            ->assertSessionHas('error');

        $this->assertSame('5.0000', (string) $source->fresh()->current_quantity);
        $this->assertSame('0.0000', (string) $target->fresh()->current_quantity);
    }

    // ─── Toner lifecycle ─────────────────────────────────

    public function test_installing_a_toner_takes_it_off_the_shelf(): void
    {
        $item = $this->item(['current_quantity' => 4, 'empty_quantity' => 0]);

        $this->actingAs($this->admin)
            ->post(route('admin.inventory.install'), [
                'inventory_item_id' => $item->id,
                'quantity'          => 1,
                'notes'             => 'Ricoh',
            ])
            ->assertSessionHas('success');

        $this->assertSame('3.0000', (string) $item->fresh()->current_quantity);
    }

    /**
     * Round 29: keyed on the field, not flashed.
     *
     * `required` and `min:` on this form never reach the server — the client
     * blocks both — so this is the only refusal an operator ever meets here.
     * As a flash it was a toast that vanished after twelve seconds, attached
     * to nothing; keyed, it renders under the quantity field and stays.
     */
    public function test_installing_more_toners_than_there_are_is_refused_on_the_quantity_field(): void
    {
        $item = $this->item(['current_quantity' => 0]);

        $this->actingAs($this->admin)
            ->post(route('admin.inventory.install'), [
                'inventory_item_id' => $item->id,
                'quantity'          => 1,
                'notes'             => 'Ricoh',
            ])
            ->assertSessionHasErrors('quantity');
    }

    public function test_a_refill_turns_empties_back_into_stock(): void
    {
        $item = $this->item(['current_quantity' => 0, 'empty_quantity' => 3, 'avg_cost' => 0]);

        $this->actingAs($this->admin)
            ->post(route('admin.inventory.refill'), [
                'inventory_item_id' => $item->id,
                'quantity'          => 2,
                'total_cost'        => 500,
                'notes'             => null,
            ])
            ->assertSessionHas('success');

        $item->refresh();

        $this->assertSame('2.0000', (string) $item->current_quantity);
        $this->assertSame('1.0000', (string) $item->empty_quantity);
    }

    public function test_refilling_more_empties_than_there_are_is_refused_on_the_quantity_field(): void
    {
        $item = $this->item(['empty_quantity' => 1]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.inventory.refill'), [
                'inventory_item_id' => $item->id,
                'quantity'          => 5,
                'total_cost'        => 100,
            ]);

        $response->assertSessionHasErrors('quantity');

        $message = (string) session('errors')->first('quantity');

        // The message already carried both numbers; keying it does not change
        // what it says, only where it is read.
        $this->assertStringContainsString('є 1', $message);

        // …and it counts them the way a person does. These columns are
        // `decimal(*, 4)`, so the sentence used to read «є 1.0000, спроба
        // заправити 5» — seen on production the day round 29 put it under the
        // field, where it is finally read closely enough to notice.
        // The exact fragment, because «contains є 1» is also true of «є
        // 1.0000» — the first version of this check asserted that and passed
        // on the very string it was written to forbid.
        $this->assertStringContainsString('є 1, спроба заправити 5', $message);
    }

    public function test_the_empty_count_can_be_corrected_in_both_directions(): void
    {
        $item = $this->item(['empty_quantity' => 2]);

        $this->actingAs($this->admin)->post(route('admin.inventory.adjust_empty'), [
            'inventory_item_id' => $item->id,
            'quantity_change'   => 3,
        ])->assertSessionHas('success');

        $this->assertSame('5.0000', (string) $item->fresh()->empty_quantity);

        $this->actingAs($this->admin)->post(route('admin.inventory.adjust_empty'), [
            'inventory_item_id' => $item->id,
            'quantity_change'   => -1,
        ])->assertSessionHas('success');

        $this->assertSame('4.0000', (string) $item->fresh()->empty_quantity);
    }

    public function test_the_empty_count_cannot_be_driven_below_zero(): void
    {
        $item = $this->item(['empty_quantity' => 1]);

        $this->actingAs($this->admin)->post(route('admin.inventory.adjust_empty'), [
            'inventory_item_id' => $item->id,
            'quantity_change'   => -5,
        ])->assertSessionHasErrors('quantity_change');

        $this->assertSame('1.0000', (string) $item->fresh()->empty_quantity);
    }

    // ─── Wiring options to stock ─────────────────────────

    public function test_relinking_an_option_recomputes_what_it_costs(): void
    {
        $paper = $this->item(['avg_cost' => 1.50]);
        $option = $this->optionLinkedTo($paper, qty: 1);

        $this->actingAs($this->admin)
            ->post(route('admin.inventory.options'), [
                'options' => [
                    ['id' => $option->id, 'inventory_item_id' => $paper->id, 'inventory_qty' => 4],
                ],
            ])
            ->assertSessionHas('success');

        $this->assertEqualsWithDelta(6.00, (float) $option->fresh()->cost_markup, 0.0001);
    }

    public function test_reordering_writes_the_positions_in_the_order_given(): void
    {
        $a = $this->item(['sort_order' => 1]);
        $b = $this->item(['sort_order' => 2]);
        $c = $this->item(['sort_order' => 3]);

        $this->actingAs($this->admin)
            ->post(route('admin.inventory.reorder'), ['ids' => [$c->id, $a->id, $b->id]])
            ->assertSessionHas('success');

        $this->assertSame(1, $c->fresh()->sort_order);
        $this->assertSame(2, $a->fresh()->sort_order);
        $this->assertSame(3, $b->fresh()->sort_order);
    }

    // ─── Access ──────────────────────────────────────────

    public function test_the_warehouse_is_closed_to_a_user_without_the_module(): void
    {
        $executor = User::factory()->create(['role' => 'executor', 'permissions' => ['orders']]);

        $this->actingAs($executor)
            ->post(route('admin.inventory.receipt'), [
                'inventory_item_id' => $this->item()->id,
                'packs_quantity'    => 1,
                'units_per_pack'    => 1,
                'price_per_pack'    => 1,
            ])
            ->assertForbidden();
    }

    private function optionLinkedTo(InventoryItem $item, float $qty): ServiceParameterOption
    {
        $service = Service::factory()->create(['type' => 'constructor']);
        $group = ServiceParameterGroup::factory()->create(['service_id' => $service->id]);

        return ServiceParameterOption::factory()->create([
            'group_id'          => $group->id,
            'inventory_item_id' => $item->id,
            'inventory_qty'     => $qty,
            'counter_type'      => 'none',
            'clicks_per_unit'   => 0,
        ]);
    }
}
