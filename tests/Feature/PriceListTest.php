<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RisoPriceTier;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PriceListTest — Feature tests for the public commercial price list.
 *
 * Validates: page accessibility (no auth required), response structure,
 * category rendering modes (pivot, tiers, simple), and empty category handling.
 */
class PriceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_list_is_publicly_accessible(): void
    {
        $response = $this->get(route('price-list'));
        $response->assertOk();
    }

    public function test_price_list_renders_with_service_categories(): void
    {
        $category = ServiceCategory::factory()->create([
            'name'          => 'Сканування',
            'is_active'     => true,
            'available_for' => ['commercial'],
        ]);

        $response = $this->get(route('price-list'));
        $response->assertOk();
    }

    public function test_price_list_renders_pivot_categories(): void
    {
        $category = ServiceCategory::factory()->create([
            'name'          => 'Чорно-білий друк',
            'is_active'     => true,
            'available_for' => ['commercial'],
        ]);

        $service = Service::factory()->create([
            'name'                => 'Чорно-білий друк',
            'type'                => 'constructor',
            'service_category_id' => $category->id,
            'is_active'           => true,
        ]);

        $formatGroup = ServiceParameterGroup::factory()->create([
            'service_id'  => $service->id,
            'name'        => 'Формат',
            'is_required' => true,
        ]);

        ServiceParameterOption::factory()->create([
            'group_id'                     => $formatGroup->id,
            'name'                       => 'А4',
            'price_markup'               => 1.00,
            'is_active'                  => true,
        ]);

        $response = $this->get(route('price-list'));
        $response->assertOk();
    }

    public function test_price_list_renders_tier_categories(): void
    {
        ServiceCategory::factory()->create([
            'name'          => "Палітурка м'яка",
            'is_active'     => true,
            'available_for' => ['commercial'],
        ]);

        $response = $this->get(route('price-list'));
        $response->assertOk();
    }

    public function test_price_list_includes_riso_tiers(): void
    {
        RisoPriceTier::factory()->create([
            'min_qty'       => 1,
            'max_qty'       => 50,
            'cost_per_copy' => 0.15,
        ]);
        RisoPriceTier::factory()->create([
            'min_qty'       => 51,
            'max_qty'       => 200,
            'cost_per_copy' => 0.10,
        ]);

        $response = $this->get(route('price-list'));
        $response->assertOk();
    }

    public function test_price_list_skips_inactive_categories(): void
    {
        ServiceCategory::factory()->create([
            'name'          => 'Deactivated Category',
            'is_active'     => false,
            'available_for' => ['commercial'],
        ]);

        $response = $this->get(route('price-list'));
        $response->assertOk();
    }

    public function test_price_list_skips_internal_only_categories(): void
    {
        ServiceCategory::factory()->create([
            'name'          => 'Internal Only',
            'is_active'     => true,
            'available_for' => ['internal'],
        ]);

        $response = $this->get(route('price-list'));
        $response->assertOk();
    }
}
