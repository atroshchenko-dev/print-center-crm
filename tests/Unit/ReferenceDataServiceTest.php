<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\RisoPriceTier;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SignatoryGroup;
use App\Models\UniversityRef;
use App\Services\ReferenceDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ReferenceDataServiceTest
 *
 * Verifies reference data queries and caching behavior.
 */
class ReferenceDataServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReferenceDataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReferenceDataService;
        Cache::flush();
    }

    public function test_categories_returns_active_only(): void
    {
        ServiceCategory::factory()->create(['is_active' => true, 'name' => 'Active']);
        ServiceCategory::factory()->create(['is_active' => false, 'name' => 'Inactive']);

        $result = $this->service->categories();

        $this->assertCount(1, $result);
    }

    public function test_services_returns_with_relations(): void
    {
        $category = ServiceCategory::factory()->create(['is_active' => true]);
        Service::factory()->create([
            'is_active'           => true,
            'service_category_id' => $category->id,
        ]);

        $result = $this->service->services();

        $this->assertCount(1, $result);
    }

    public function test_riso_tiers_ordered_by_min_qty(): void
    {
        // Clean existing tiers to avoid seed data interference
        RisoPriceTier::query()->forceDelete();

        RisoPriceTier::factory()->create(['min_qty' => 100, 'cost_per_copy' => 0.50]);
        RisoPriceTier::factory()->create(['min_qty' => 1, 'cost_per_copy' => 1.00]);

        Cache::flush();
        $result = $this->service->risoTiers();

        $this->assertCount(2, $result);
        $this->assertEquals(1, $result->first()->min_qty);
        $this->assertEquals(100, $result->last()->min_qty);
    }

    public function test_flush_clears_cache(): void
    {
        // Prime the cache
        $this->service->categories();
        $this->assertTrue(Cache::has('ref:categories'));

        $this->service->flush();

        $this->assertFalse(Cache::has('ref:categories'));
    }

    public function test_flush_specific_key(): void
    {
        $this->service->categories();
        $this->service->risoTiers();

        $this->service->flush('categories');

        $this->assertFalse(Cache::has('ref:categories'));
        $this->assertTrue(Cache::has('ref:riso_tiers'));
    }

    /**
     * `Orders/Create.vue` and its siblings read `signatory.group.categories`
     * to decide which category tabs to offer, and this eager-load had no
     * `withTrashed()` on the group — so an active signatory whose group was
     * deactivated looked ungrouped to the client, which then offered every
     * tab. `LimitService::checkSignatoryLimit()` and
     * `servicesOutsideSignatoryCategories()` already carry `withTrashed()` on
     * this exact relation, so the server still enforced the group's
     * categories: the order warned on tabs the client itself had offered.
     */
    public function test_signatories_keeps_the_group_of_a_deactivated_group(): void
    {
        $category = ServiceCategory::factory()->create(['name' => 'Друк']);
        $group = SignatoryGroup::factory()->create(['name' => 'Деканат']);
        $group->categories()->sync([$category->id]);

        UniversityRef::factory()->create([
            'full_name'          => 'Іваненко Іван Іванович',
            'signatory_group_id' => $group->id,
            'is_active'          => true,
        ]);

        $group->delete();

        $signatory = $this->service->signatories()->firstWhere('full_name', 'Іваненко Іван Іванович');

        $this->assertNotNull($signatory->group, 'A deactivated group is not the same fact as no group at all');
        $this->assertSame('Деканат', $signatory->group->name);
        $this->assertSame(['Друк'], $signatory->group->categories->pluck('name')->all());
    }
}
