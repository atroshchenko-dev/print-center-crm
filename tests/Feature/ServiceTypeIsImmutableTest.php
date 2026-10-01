<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UpdateServiceRequest omits `type` on purpose: historical orders were priced by
 * the type they were placed under, so it cannot change after creation. That
 * decision lived in a comment and nowhere else, while the edit form offered a
 * "Тип" dropdown, rebuilt itself around the choice and reported "Послугу
 * оновлено" — three signals that something had changed, over a server that had
 * refused. The dropdown is gone; this is the half that has to stay true, and
 * the reason the dropdown must not come back.
 */
class ServiceTypeIsImmutableTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'role'        => 'admin',
            'permissions' => User::permissionKeys(),
        ]);
    }

    private function service(string $type = 'static'): Service
    {
        return Service::factory()->create([
            'name'                  => 'Друк А4',
            'type'                  => $type,
            'service_category_id'   => ServiceCategory::factory()->create()->id,
            'base_price_commercial' => 5.00,
            'base_price_cost'       => 2.00,
        ]);
    }

    public function test_the_update_endpoint_does_not_change_the_service_type(): void
    {
        $service = $this->service('static');

        $this->actingAs($this->admin())
            ->patch(route('admin.services.update', $service), [
                'type'                  => 'constructor',
                'name'                  => 'Друк А4',
                'base_price_commercial' => 5.00,
                'base_price_cost'       => 2.00,
                'counter_type'          => 'bw',
                'clicks_per_unit'       => 1,
                'is_active'             => true,
                'service_category_id'   => $service->service_category_id,
            ])
            ->assertRedirect(route('admin.services.index'));

        $this->assertSame('static', $service->fresh()->type->value);
    }

    /**
     * The rest of the form has to keep working — the point is that `type` is the
     * one field that does not move, not that the endpoint ignores its payload.
     */
    public function test_the_update_endpoint_still_saves_everything_else(): void
    {
        $service = $this->service('constructor');
        $category = ServiceCategory::factory()->create(['name' => 'Інше']);

        $this->actingAs($this->admin())
            ->patch(route('admin.services.update', $service), [
                'name'                  => 'Друк А4 (кольоровий)',
                'base_price_commercial' => 9.50,
                'base_price_cost'       => 4.25,
                'counter_type'          => 'color',
                'clicks_per_unit'       => 2,
                'is_active'             => false,
                'service_category_id'   => $category->id,
            ]);

        $fresh = $service->fresh();

        $this->assertSame('Друк А4 (кольоровий)', $fresh->name);
        $this->assertSame('9.50', (string) $fresh->base_price_commercial);
        $this->assertSame('color', $fresh->counter_type->value);
        $this->assertSame($category->id, $fresh->service_category_id);
        $this->assertFalse($fresh->is_active);
        $this->assertSame('constructor', $fresh->type->value);
    }

    /**
     * `ui_style` decides how ConstructorPanel draws a parameter group, the
     * column has been there since April, the rule accepts it and the panel
     * reads it — and the admin form never sent it, so every group added through
     * the admin stayed on the column default `chips` for good and changing it
     * meant SQL. The control exists now; this is the half that has to keep
     * working underneath it.
     */
    public function test_a_parameter_group_can_be_created_and_restyled_from_the_admin(): void
    {
        $service = $this->service('constructor');

        $this->actingAs($this->admin())
            ->post(route('admin.service-groups.store', $service), [
                'name'        => 'Формат',
                'ui_type'     => 'radio',
                'ui_style'    => 'tiles_large',
                'is_required' => true,
                'sort_order'  => 10,
            ])
            ->assertSessionHasNoErrors();

        $group = $service->parameterGroups()->sole();
        $this->assertSame('tiles_large', $group->ui_style);

        $this->actingAs($this->admin())
            ->patch(route('admin.service-groups.update', $group), [
                'name'        => 'Формат',
                'ui_type'     => 'radio',
                'ui_style'    => 'dropdown',
                'is_required' => true,
                'sort_order'  => 10,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('dropdown', $group->fresh()->ui_style);
    }

    public function test_an_unknown_group_style_is_refused(): void
    {
        $service = $this->service('constructor');

        $this->actingAs($this->admin())
            ->post(route('admin.service-groups.store', $service), [
                'name'     => 'Формат',
                'ui_type'  => 'radio',
                'ui_style' => 'carousel',
            ])
            ->assertSessionHasErrors('ui_style');
    }
}
