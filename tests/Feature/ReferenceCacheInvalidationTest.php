<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\RisoPriceTier;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Models\Setting;
use App\Models\SignatoryCostCenter;
use App\Models\SignatoryGroup;
use App\Models\UniversityRef;
use App\Models\User;
use App\Services\ReferenceDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Editing reference data must be visible immediately.
 *
 * ReferenceDataService caches five lists for an hour, and invalidation was the
 * caller's job. Three callers did it; the ones editing parameter groups,
 * parameter options, signatories and departments did not — and parameter
 * options are where constructor prices live. An admin changed a price and the
 * order screen kept the old option list for up to an hour.
 *
 * That is almost certainly why someone set cache_enabled=false on 2026-04-26.
 * The migration on 2026-05-12 seeded it back to true without reading the file,
 * so the workaround was silently undone and the symptom returned.
 */
class ReferenceCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The whole point is the behaviour with caching switched on.
        Setting::setValue('cache_enabled', true);
        Cache::flush();
    }

    private function refData(): ReferenceDataService
    {
        return app(ReferenceDataService::class);
    }

    /**
     * The case that hurt: a price edited in the price-list module.
     */
    public function test_editing_an_option_price_is_visible_at_once(): void
    {
        $category = ServiceCategory::factory()->create(['is_active' => true]);
        $service = Service::factory()->create([
            'service_category_id' => $category->id,
            'is_active'           => true,
        ]);
        $group = ServiceParameterGroup::factory()->create(['service_id' => $service->id]);
        $option = ServiceParameterOption::factory()->create([
            'group_id'     => $group->id,
            'price_markup' => 45.00,
            'is_active'    => true,
        ]);

        // Warm the cache the way the order screen does.
        $this->assertEquals(
            45.00,
            (float) $this->refData()->services()
                ->firstWhere('id', $service->id)
                ->parameterGroups->first()
                ->options->first()->price_markup,
        );

        $option->update(['price_markup' => 61.00]);

        $this->assertEquals(
            61.00,
            (float) $this->refData()->services()
                ->firstWhere('id', $service->id)
                ->parameterGroups->first()
                ->options->first()->price_markup,
            'The new price has to reach the order screen now, not in an hour.',
        );
    }

    public function test_a_new_option_appears_at_once(): void
    {
        $category = ServiceCategory::factory()->create(['is_active' => true]);
        $service = Service::factory()->create([
            'service_category_id' => $category->id,
            'is_active'           => true,
        ]);
        $group = ServiceParameterGroup::factory()->create(['service_id' => $service->id]);

        $this->refData()->services();

        ServiceParameterOption::factory()->create([
            'group_id'  => $group->id,
            'name'      => 'Нова опція',
            'is_active' => true,
        ]);

        $names = $this->refData()->services()
            ->firstWhere('id', $service->id)
            ->parameterGroups->first()
            ->options->pluck('name');

        $this->assertContains('Нова опція', $names);
    }

    /**
     * Deactivating is the dangerous direction — a stale list lets an operator
     * pick something the pricing endpoint will then refuse to count.
     */
    public function test_deactivating_an_option_removes_it_at_once(): void
    {
        $category = ServiceCategory::factory()->create(['is_active' => true]);
        $service = Service::factory()->create([
            'service_category_id' => $category->id,
            'is_active'           => true,
        ]);
        $group = ServiceParameterGroup::factory()->create(['service_id' => $service->id]);
        $option = ServiceParameterOption::factory()->create([
            'group_id'  => $group->id,
            'name'      => 'Знята опція',
            'is_active' => true,
        ]);

        $this->refData()->services();

        $option->delete();

        $names = $this->refData()->services()
            ->firstWhere('id', $service->id)
            ->parameterGroups->first()
            ->options->pluck('name');

        $this->assertNotContains('Знята опція', $names);
    }

    public function test_a_new_signatory_appears_at_once(): void
    {
        $this->refData()->signatories();

        UniversityRef::factory()->create([
            'full_name' => 'Нечвак О. В.',
            'is_active' => true,
        ]);

        $this->assertContains(
            'Нечвак О. В.',
            $this->refData()->signatories()->pluck('full_name'),
        );
    }

    /**
     * Departments are also created on the fly by firstOrCreate() while an
     * order is being saved, so this one had no controller to blame at all.
     */
    public function test_a_new_department_appears_at_once(): void
    {
        $this->refData()->departments();

        Department::create([
            'name'      => 'Кафедра геодезії',
            'type'      => 'department',
            'is_active' => true,
        ]);

        $this->assertContains(
            'Кафедра геодезії',
            $this->refData()->departments()->pluck('name'),
        );
    }

    /**
     * The pair rows are written by `OrderObserver` while an order is saved, so
     * this list had no controller to blame either — and the admin endpoints
     * that do flush it are not the path that fills it. Without invalidation the
     * first order pairing a signatory with a centre wrote the row and the next
     * order still saw the whole book for up to an hour, while the same save
     * flushed `ref:departments` through `Department::remember()`: half of one
     * save's effects visible, half not.
     */
    public function test_a_new_signatory_cost_centre_pair_appears_at_once(): void
    {
        $signatory = UniversityRef::factory()->create();
        $department = Department::factory()->create(['name' => 'Відділ аспірантури']);

        $this->refData()->signatoryCostCenters();

        SignatoryCostCenter::create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
            'orders_count'      => 1,
        ]);

        $this->assertContains(
            'Відділ аспірантури',
            collect($this->refData()->signatoryCostCenters()[$signatory->id] ?? [])->pluck('name'),
        );
    }

    /**
     * The map is built by joining `departments` and reading `name` from it, so
     * a rename changes the map without touching a single `signatory_cost_centers`
     * row — and `Department`'s own invalidation only ever dropped
     * `ref:departments`.
     *
     * The stale map is not cosmetic. `CostCenterSelect` auto-fills a signatory's
     * only centre, so within the hour the form would hand the old spelling back,
     * `Department::remember()` would find no row under it, and the branch would
     * re-create the very duplicate it exists to stop — the reference book already
     * holds «Департамерт реклами» beside «Департамент реклами».
     */
    public function test_renaming_a_department_reaches_the_signatory_map_at_once(): void
    {
        $signatory = UniversityRef::factory()->create();
        $department = Department::factory()->create(['name' => 'Департамерт реклами']);

        SignatoryCostCenter::create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
            'orders_count'      => 3,
        ]);

        $this->assertContains(
            'Департамерт реклами',
            collect($this->refData()->signatoryCostCenters()[$signatory->id])->pluck('name'),
        );

        $department->update(['name' => 'Відділ реклами']);

        $this->assertSame(
            ['Відділ реклами'],
            collect($this->refData()->signatoryCostCenters()[$signatory->id])->pluck('name')->all(),
        );
    }

    /**
     * Deactivating a department drops its pairs from the map for the same
     * reason and through the same join — the spec's «зникає з підказок».
     */
    public function test_deactivating_a_department_leaves_the_signatory_map_at_once(): void
    {
        $signatory = UniversityRef::factory()->create();
        $department = Department::factory()->create(['name' => 'Кафедра ІМЗД']);

        SignatoryCostCenter::create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
            'orders_count'      => 3,
        ]);

        $this->refData()->signatoryCostCenters();

        $department->delete();

        $this->assertArrayNotHasKey($signatory->id, $this->refData()->signatoryCostCenters()->all());
    }

    /**
     * §10 of the design asks for this one by name: the shared `flush()` has to
     * drop the new key too, or removing a wrong pair in the admin screen would
     * not show on the form until the TTL ran out.
     */
    public function test_flush_drops_the_signatory_cost_centre_map(): void
    {
        $signatory = UniversityRef::factory()->create();
        $department = Department::factory()->create();

        SignatoryCostCenter::create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
            'orders_count'      => 1,
        ]);

        $this->refData()->signatoryCostCenters();
        $this->assertTrue(Cache::has('ref:signatory_cost_centers'));

        $this->refData()->flush();

        $this->assertFalse(Cache::has('ref:signatory_cost_centers'));
    }

    public function test_a_new_riso_tier_appears_at_once(): void
    {
        $this->refData()->risoTiers();

        RisoPriceTier::factory()->create(['min_qty' => 501, 'max_qty' => 1000]);

        $this->assertContains(
            501,
            $this->refData()->risoTiers()->pluck('min_qty')->map(fn ($v) => (int) $v),
        );
    }

    public function test_a_new_category_appears_at_once(): void
    {
        $this->refData()->categories();

        ServiceCategory::factory()->create([
            'name'      => 'Гравіювання',
            'is_active' => true,
        ]);

        $this->assertContains(
            'Гравіювання',
            $this->refData()->categories()->pluck('name'),
        );
    }

    /**
     * The Settings page toggle kept its own copy of the key list — five
     * Cache::forget() calls that happened to match the service's. R3-16 was
     * about exactly that: invalidation knowledge spread across call sites
     * falls behind the moment a sixth list is added. The toggle now asks the
     * service, and this pins that a key added there is dropped here too.
     */
    public function test_the_settings_toggle_drops_every_reference_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->refData()->categories();
        $this->refData()->services();
        $this->refData()->risoTiers();
        $this->refData()->departments();
        $this->refData()->signatories();
        Cache::put('dashboard:charts', ['stale'], 300);

        $this->actingAs($admin)
            ->post(route('admin.settings.toggle-cache'))
            ->assertRedirect();

        foreach (['ref:categories', 'ref:services', 'ref:riso_tiers', 'ref:departments', 'ref:signatories', 'dashboard:charts'] as $key) {
            $this->assertFalse(Cache::has($key), "{$key} survived the cache toggle.");
        }
    }

    public function test_the_settings_toggle_is_closed_to_operators(): void
    {
        $executor = User::factory()->create(['role' => 'executor']);

        $this->actingAs($executor)
            ->post(route('admin.settings.toggle-cache'))
            ->assertForbidden();
    }

    /**
     * Скидання, зроблене всередині транзакції, мусить дочекатися її коміту:
     * інакше у вікні «forget → commit» паралельний читач закешує ще
     * доком'ітний стан на повний TTL — годину.
     */
    public function test_the_forget_waits_for_the_transaction_to_commit(): void
    {
        $department = Department::factory()->create(['is_active' => true]);

        $this->refData()->departments();
        $this->assertTrue(Cache::has('ref:departments'));

        DB::transaction(function () use ($department) {
            $department->update(['name' => 'Перейменований підрозділ']);

            // Ще не закомічено — кеш мусить стояти: читач зараз має бачити
            // стару, але узгоджену картину, а не кешувати проміжну.
            $this->assertTrue(Cache::has('ref:departments'));
        });

        $this->assertFalse(Cache::has('ref:departments'));
    }

    /**
     * The category tabs on all four order forms are drawn from this cached list,
     * and so is the rule that drops cart items when a signatory is switched. An
     * admin narrowing a group and an operator seeing the old set for an hour is
     * the shape this whole test file exists for — `SignatoryGroup` has no
     * invalidation trait, and `sync()` fires no model events for one to catch.
     */
    public function test_narrowing_a_groups_categories_reaches_the_order_form_at_once(): void
    {
        $admin = User::factory()->admin()->create();

        $print = ServiceCategory::factory()->create(['is_active' => true]);
        $binding = ServiceCategory::factory()->create(['is_active' => true]);

        $group = SignatoryGroup::factory()->create(['name' => 'Деканат', 'daily_limit' => null]);
        $group->categories()->sync([$print->id, $binding->id]);

        $signatory = UniversityRef::factory()->create([
            'full_name'          => 'Іваненко Іван Іванович',
            'signatory_group_id' => $group->id,
            'is_active'          => true,
        ]);

        // Warm the cache the way the order form does.
        $this->assertCount(
            2,
            $this->refData()->signatories()->firstWhere('id', $signatory->id)->group->categories,
        );

        $this->actingAs($admin)->patch(route('admin.university.groups.update', $group), [
            'name'         => 'Деканат',
            'category_ids' => [$print->id],
        ])->assertRedirect();

        $this->assertCount(
            1,
            $this->refData()->signatories()->firstWhere('id', $signatory->id)->group->categories,
            'The narrowed list has to reach the order form now, not in an hour.',
        );
    }
}
