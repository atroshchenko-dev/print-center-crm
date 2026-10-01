<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SignatoryGroup;
use App\Models\UniversityRef;
use App\Models\User;
use App\Services\ReferenceDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The two reference screens round 18's brief named as the thinnest covered
 * controllers left: `ServiceController` (46,2%) and `UniversityController`
 * (43,1%).
 *
 * Coverage is the reason these were picked, but not what they assert. Each test
 * here states a rule the screens already depend on and nothing was holding:
 * that a deactivated row is still reachable, that a saved reference clears the
 * hour-long cache the order screen reads from, and that the module gate is on
 * every one of these routes.
 */
class ReferenceCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    // ─── Services ────────────────────────────────────────

    public function test_the_service_list_hides_deactivated_rows_until_asked(): void
    {
        $category = ServiceCategory::factory()->create();
        $live = Service::factory()->create(['name' => 'Жива послуга', 'service_category_id' => $category->id]);
        $gone = Service::factory()->create(['name' => 'Знята послуга', 'service_category_id' => $category->id]);

        $gone->delete();

        $this->actingAs($this->admin)->get(route('admin.services.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Services/Index')
                ->where('showTrashed', false)
                ->has('services', 1)
                ->where('services.0.id', $live->id));

        $this->actingAs($this->admin)->get(route('admin.services.index', ['trashed' => 1]))
            ->assertInertia(fn ($page) => $page
                ->where('showTrashed', true)
                ->has('services', 2));
    }

    public function test_a_new_service_is_saved_and_the_reference_cache_is_dropped(): void
    {
        $category = ServiceCategory::factory()->create();

        Cache::put('ref:services', ['stale'], 3600);

        $this->actingAs($this->admin)->post(route('admin.services.store'), [
            'name' => 'Нова послуга',
            'service_category_id' => $category->id,
            'type' => 'static',
            'base_price_cost' => 3.50,
            'base_price_commercial' => 7.00,
            'counter_type' => 'bw',
            'clicks_per_unit' => 1,
            'unit' => 'шт',
            'is_active' => true,
            'available_for' => ['internal'],
        ])->assertRedirect(route('admin.services.index'));

        $this->assertDatabaseHas('services', ['name' => 'Нова послуга']);

        // The order screen reads this list for an hour; a price saved into a
        // stale list is the bug `InvalidatesReferenceCache` was written for.
        $this->assertNull(Cache::get('ref:services'));
    }

    public function test_editing_a_service_loads_its_parameters_and_saves_a_new_price(): void
    {
        $category = ServiceCategory::factory()->create();
        $service = Service::factory()->create([
            'service_category_id' => $category->id,
            'type' => 'static',
            'base_price_cost' => 1.00,
            'base_price_commercial' => 2.00,
        ]);

        $this->actingAs($this->admin)->get(route('admin.services.edit-page', $service))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Services/Form')
                ->where('service.id', $service->id)
                ->has('inventoryItems')
                ->has('materials'));

        Cache::put('ref:services', ['stale'], 3600);

        $this->actingAs($this->admin)->patch(route('admin.services.update', $service), [
            'name' => $service->name,
            'service_category_id' => $category->id,
            'base_price_cost' => 9.99,
            'base_price_commercial' => 19.99,
            'counter_type' => $service->counter_type->value,
            'clicks_per_unit' => $service->clicks_per_unit,
            'unit' => $service->unit,
            'is_active' => true,
            'available_for' => ['internal', 'commercial'],
        ])->assertRedirect(route('admin.services.index'));

        $this->assertEquals(9.99, $service->fresh()->base_price_cost);
        $this->assertNull(Cache::get('ref:services'));
    }

    public function test_deactivating_a_service_keeps_the_row(): void
    {
        $service = Service::factory()->create();

        $this->actingAs($this->admin)->delete(route('admin.services.destroy', $service))
            ->assertRedirect();

        $this->assertSoftDeleted('services', ['id' => $service->id]);
    }

    public function test_the_service_screen_is_behind_its_module_permission(): void
    {
        $stranger = User::factory()->create(['role' => 'manager', 'permissions' => ['orders']]);
        $service = Service::factory()->create();

        $this->actingAs($stranger)->get(route('admin.services.index'))->assertForbidden();
        $this->actingAs($stranger)->delete(route('admin.services.destroy', $service))->assertForbidden();
    }

    // ─── University: groups ──────────────────────────────

    public function test_a_new_group_goes_to_the_end_of_the_list_with_its_categories(): void
    {
        $first = SignatoryGroup::factory()->create(['sort_order' => 10]);
        $category = ServiceCategory::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.university.groups.store'), [
            'name' => 'Ректорат',
            'daily_limit' => 25,
            'is_active' => true,
            'category_ids' => [$category->id],
        ])->assertRedirect();

        $group = SignatoryGroup::where('name', 'Ректорат')->first();

        $this->assertSame($first->sort_order + 10, $group->sort_order);
        $this->assertSame([$category->id], $group->categories->pluck('id')->all());
    }

    public function test_editing_a_group_replaces_its_categories(): void
    {
        $old = ServiceCategory::factory()->create();
        $new = ServiceCategory::factory()->create();

        $group = SignatoryGroup::factory()->create(['daily_limit' => 10]);
        $group->categories()->sync([$old->id]);

        $this->actingAs($this->admin)->patch(route('admin.university.groups.update', $group), [
            'name' => 'Деканат',
            'daily_limit' => 40,
            'is_active' => true,
            'category_ids' => [$new->id],
        ])->assertRedirect();

        $group->refresh();

        $this->assertSame('Деканат', $group->name);
        $this->assertSame(40, $group->daily_limit);
        $this->assertSame([$new->id], $group->categories->pluck('id')->all());
    }

    /**
     * A deactivated group keeps its members and keeps its quota: deactivating it
     * is not a decision to grant unlimited printing, which is why
     * `LimitService::checkSignatoryLimit()` resolves the group `withTrashed()`.
     */
    public function test_a_deactivated_group_still_holds_its_signatories(): void
    {
        $group = SignatoryGroup::factory()->create(['daily_limit' => 10]);
        $signatory = UniversityRef::factory()->create(['signatory_group_id' => $group->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.university.groups.destroy', $group))
            ->assertRedirect();

        $this->assertSoftDeleted('signatory_groups', ['id' => $group->id]);
        $this->assertSame($group->id, $signatory->fresh()->signatory_group_id);
    }

    // ─── University: signatories and departments ─────────

    public function test_a_signatory_is_created_edited_and_deactivated(): void
    {
        $group = SignatoryGroup::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.university.signatories.store'), [
            'full_name' => 'Іваненко Іван Іванович',
            'position' => 'Декан',
            'email' => 'approver@example.edu',
            'signatory_group_id' => $group->id,
            'is_active' => true,
        ])->assertRedirect();

        $signatory = UniversityRef::where('full_name', 'Іваненко Іван Іванович')->firstOrFail();

        $this->actingAs($this->admin)->patch(route('admin.university.signatories.update', $signatory), [
            'full_name' => 'Іваненко Іван Іванович',
            'position' => 'Проректор',
            'signatory_group_id' => $group->id,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertSame('Проректор', $signatory->fresh()->position);

        $this->actingAs($this->admin)
            ->delete(route('admin.university.signatories.destroy', $signatory))
            ->assertRedirect();

        $this->assertSoftDeleted('university_refs', ['id' => $signatory->id]);
    }

    public function test_a_cost_centre_is_created_edited_and_deactivated(): void
    {
        $this->actingAs($this->admin)->post(route('admin.university.departments.store'), [
            'name' => 'Кафедра філософії',
            'type' => 'department',
            'is_active' => true,
        ])->assertRedirect();

        $department = Department::where('name', 'Кафедра філософії')->firstOrFail();

        $this->actingAs($this->admin)->patch(route('admin.university.departments.update', $department), [
            'name' => 'Кафедра філософії',
            'type' => 'project',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertTrue($department->fresh()->isProject());

        $this->actingAs($this->admin)
            ->delete(route('admin.university.departments.destroy', $department))
            ->assertRedirect();

        $this->assertSoftDeleted('departments', ['id' => $department->id]);
    }

    /**
     * All three tabs arrive with their deactivated rows — the page draws a
     * «Видалено» badge for them, and until round 15 the queries carried the
     * soft-delete scope, so that badge could never render.
     */
    public function test_the_page_carries_deactivated_rows_on_every_tab(): void
    {
        $group = SignatoryGroup::factory()->create();
        $signatory = UniversityRef::factory()->create(['signatory_group_id' => $group->id]);
        $department = Department::create(['name' => 'Знято', 'type' => 'department', 'is_active' => true]);

        $group->delete();
        $signatory->delete();
        $department->delete();

        $this->actingAs($this->admin)->get(route('admin.university.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/University/Index')
                ->has('signatoryGroups', 1)
                ->has('signatories', 1)
                ->has('departments', 1)
                ->has('serviceCategories'));
    }

    public function test_the_university_screen_is_behind_its_module_permission(): void
    {
        $stranger = User::factory()->create(['role' => 'manager', 'permissions' => ['orders']]);

        $this->actingAs($stranger)->get(route('admin.university.index'))->assertForbidden();
        $this->actingAs($stranger)->post(route('admin.university.departments.store'), [
            'name' => 'Чужий підрозділ',
            'type' => 'department',
        ])->assertForbidden();
    }

    /**
     * The reference cache is dropped by the model, not by the controller — so a
     * signatory saved from anywhere invalidates the list the order screen reads.
     */
    public function test_saving_a_signatory_drops_the_cached_list(): void
    {
        Cache::put('ref:signatories', ['stale'], 3600);

        UniversityRef::factory()->create(['full_name' => 'Петренко Петро Петрович']);

        $this->assertNull(Cache::get('ref:signatories'));

        $fresh = app(ReferenceDataService::class)->signatories();

        $this->assertNotEmpty($fresh);
    }
}
