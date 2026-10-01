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
 * The services list needs the option prices to render its ЦІНА column, which
 * shows the span the options cover rather than a constructor's base price of
 * zero. This pins the shape the page relies on: `parameter_groups[].options[]`
 * with a `price_markup` on each.
 */
class ServiceIndexPriceLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_carries_option_prices_for_each_service(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $category = ServiceCategory::factory()->create(['is_active' => true]);
        $service  = Service::factory()->create([
            'name'                  => 'Палітурка на пружині',
            'service_category_id'   => $category->id,
            'type'                  => 'constructor',
            'base_price_commercial' => 0,
            'is_active'             => true,
        ]);

        $group = ServiceParameterGroup::factory()->create([
            'service_id' => $service->id,
            'name'       => 'Пружина',
        ]);

        foreach ([45.00, 160.00] as $price) {
            ServiceParameterOption::factory()->create([
                'group_id'     => $group->id,
                'price_markup' => $price,
                'is_active'    => true,
            ]);
        }

        $response = $this->actingAs($admin)->get(route('admin.services.index'));
        $response->assertOk();

        $services = $response->viewData('page')['props']['services'];
        $row      = collect($services)->firstWhere('id', $service->id);

        $this->assertNotNull($row);

        $markups = collect($row['parameter_groups'])
            ->flatMap(fn ($g) => $g['options'])
            ->pluck('price_markup')
            ->map(fn ($v) => (float) $v);

        $this->assertEqualsCanonicalizing(
            [45.00, 160.00],
            $markups->all(),
            'The column renders a range from these, so they have to be here.',
        );

        $this->assertEquals(
            0,
            (float) $row['base_price_commercial'],
            'A constructor prices through options — which is why showing the base was misleading.',
        );
    }
}
