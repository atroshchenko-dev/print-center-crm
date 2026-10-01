<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing a constructor option — the endpoint nothing had ever called.
 *
 * The admin form could only add and delete options, so changing a price meant
 * deleting one and creating it again. That mints a new id, and options in
 * other groups point at ids through depends_on: lamination films hang off a
 * format option, hard-binding colours hang off a size. Re-creating a parent
 * silently orphans its children.
 *
 * The update route existed the whole time and had no tests either.
 */
class ServiceParameterOptionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private ServiceParameterGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        $category = ServiceCategory::factory()->create(['is_active' => true]);
        $service  = Service::factory()->create([
            'service_category_id' => $category->id,
            'is_active'           => true,
        ]);

        $this->group = ServiceParameterGroup::factory()->create(['service_id' => $service->id]);
    }

    public function test_an_admin_can_change_an_option_price(): void
    {
        $option = ServiceParameterOption::factory()->create([
            'group_id'     => $this->group->id,
            'name'         => '6 мм (до 25 арк.)',
            'price_markup' => 45.00,
            'is_active'    => true,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-options.update', $option), [
                'name'            => '6 мм (до 25 арк.)',
                'price_markup'    => 46.00,
                'inventory_qty'   => 1,
                'counter_type'    => 'none',
                'clicks_per_unit' => 0,
                'is_active'       => true,
            ])
            ->assertSessionHas('success');

        $this->assertEquals(46.00, (float) $option->fresh()->price_markup);
    }

    /**
     * The id has to survive, because other options reference it.
     */
    public function test_editing_keeps_the_option_id_and_its_dependents(): void
    {
        $parent = ServiceParameterOption::factory()->create([
            'group_id'     => $this->group->id,
            'name'         => 'А4',
            'price_markup' => 0,
            'is_active'    => true,
        ]);

        $childGroup = ServiceParameterGroup::factory()->create([
            'service_id' => $this->group->service_id,
            'name'       => 'Тип плівки',
        ]);

        $child = ServiceParameterOption::factory()->create([
            'group_id'     => $childGroup->id,
            'name'         => 'А4: 100 мкм',
            'price_markup' => 27.00,
            'is_active'    => true,
            'depends_on'   => [
                'group_id'   => $this->group->id,
                'option_ids' => [$parent->id],
            ],
        ]);

        $originalId = $parent->id;

        $this->actingAs($this->admin)
            ->patch(route('admin.service-options.update', $parent), [
                'name'            => 'А4 (210×297)',
                'price_markup'    => 0,
                'inventory_qty'   => 1,
                'counter_type'    => 'none',
                'clicks_per_unit' => 0,
                'is_active'       => true,
            ]);

        $parent->refresh();

        $this->assertSame($originalId, $parent->id, 'The id must not change.');
        $this->assertSame('А4 (210×297)', $parent->name);
        $this->assertSame(
            [$originalId],
            $child->fresh()->depends_on['option_ids'],
            'The dependent option must still point at a live parent.',
        );
    }

    /**
     * The form does not submit depends_on, so an edit must leave it alone
     * rather than blanking it.
     */
    public function test_editing_does_not_wipe_a_dependency(): void
    {
        $parent = ServiceParameterOption::factory()->create([
            'group_id'  => $this->group->id,
            'is_active' => true,
        ]);

        $option = ServiceParameterOption::factory()->create([
            'group_id'     => $this->group->id,
            'price_markup' => 10.00,
            'is_active'    => true,
            'depends_on'   => [
                'group_id'   => $this->group->id,
                'option_ids' => [$parent->id],
            ],
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-options.update', $option), [
                'name'            => $option->name,
                'price_markup'    => 12.00,
                'inventory_qty'   => 1,
                'counter_type'    => 'none',
                'clicks_per_unit' => 0,
                'is_active'       => true,
            ]);

        $this->assertNotNull(
            $option->fresh()->depends_on,
            'An edit that never mentioned depends_on must not clear it.',
        );
    }

    public function test_a_negative_price_is_refused(): void
    {
        $option = ServiceParameterOption::factory()->create([
            'group_id'     => $this->group->id,
            'price_markup' => 45.00,
            'is_active'    => true,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-options.update', $option), [
                'name'            => $option->name,
                'price_markup'    => -5,
                'inventory_qty'   => 1,
                'counter_type'    => 'none',
                'clicks_per_unit' => 0,
                'is_active'       => true,
            ])
            ->assertSessionHasErrors('price_markup');

        $this->assertEquals(45.00, (float) $option->fresh()->price_markup);
    }

    public function test_an_operator_cannot_edit_prices(): void
    {
        $option = ServiceParameterOption::factory()->create([
            'group_id'     => $this->group->id,
            'price_markup' => 45.00,
            'is_active'    => true,
        ]);

        $executor = User::factory()->create(['role' => 'executor', 'permissions' => []]);

        $this->actingAs($executor)
            ->patch(route('admin.service-options.update', $option), [
                'name'            => $option->name,
                'price_markup'    => 1.00,
                'inventory_qty'   => 1,
                'counter_type'    => 'none',
                'clicks_per_unit' => 0,
                'is_active'       => true,
            ])
            ->assertForbidden();

        $this->assertEquals(45.00, (float) $option->fresh()->price_markup);
    }
}
