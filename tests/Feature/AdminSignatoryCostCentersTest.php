<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SignatoryCostCenter;
use App\Models\UniversityRef;
use App\Models\User;
use App\Services\ReferenceDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Мапа «підписант → його центри витрат» — те, чим форма звужує півсотні
 * позицій до однієї-двох. Порядок у ній не косметика: перший елемент
 * підставляється в поле, коли центр один.
 */
class AdminSignatoryCostCentersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_map_puts_the_most_used_centre_first(): void
    {
        $signatory = UniversityRef::factory()->create(['full_name' => 'Накченко Н.В.']);
        $rare = Department::factory()->create(['name' => 'КЛБ']);
        $common = Department::factory()->create(['name' => 'Департамент реклами']);

        SignatoryCostCenter::factory()->create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $rare->id,
            'orders_count'      => 2,
        ]);
        SignatoryCostCenter::factory()->create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $common->id,
            'orders_count'      => 40,
        ]);

        $map = app(ReferenceDataService::class)->signatoryCostCenters();

        $this->assertSame(
            ['Департамент реклами', 'КЛБ'],
            collect($map[$signatory->id])->pluck('name')->all(),
        );
    }

    public function test_a_deactivated_centre_leaves_the_map(): void
    {
        $signatory = UniversityRef::factory()->create();
        $department = Department::factory()->create(['name' => 'ДКДЗ']);

        SignatoryCostCenter::factory()->create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
        ]);

        $department->delete();

        $map = app(ReferenceDataService::class)->signatoryCostCenters();

        $this->assertArrayNotHasKey($signatory->id, $map->all());
    }

    public function test_the_signatory_list_arrives_ordered_by_name(): void
    {
        UniversityRef::factory()->create(['full_name' => 'Ярова О.П.']);
        UniversityRef::factory()->create(['full_name' => 'Балдець Д.О.']);
        UniversityRef::factory()->create(['full_name' => 'Новаський І.М.']);

        $names = app(ReferenceDataService::class)->signatories()->pluck('full_name')->all();

        $this->assertSame(['Балдець Д.О.', 'Новаський І.М.', 'Ярова О.П.'], $names);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_the_page_shows_each_signatory_with_their_centres(): void
    {
        $signatory = UniversityRef::factory()->create(['full_name' => 'Накченко Н.В.']);
        $department = Department::factory()->create(['name' => 'Департамент реклами']);

        SignatoryCostCenter::factory()->create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
            'orders_count'      => 7,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.university.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('signatories.0.cost_centers.0.name', 'Департамент реклами')
                ->where('signatories.0.cost_centers.0.orders_count', 7));
    }

    /**
     * A pair survives its department being deactivated — `test_an_admin_can_
     * remove_a_pair_naming_a_deactivated_department` below is that case, and it
     * is the case this screen exists for. Under the soft-delete scope the
     * relation resolved to null, the row shipped `'name' => null`, and the
     * screen drew a blank line with a ✕ next to it: on the one page built for
     * removing wrong pairs, the admin could not tell which pair they were
     * about to remove.
     */
    public function test_the_page_names_a_centre_whose_department_was_deactivated(): void
    {
        $signatory = UniversityRef::factory()->create(['full_name' => 'Накченко Н.В.']);
        $department = Department::factory()->create(['name' => 'Кафедра ІМЗД']);

        SignatoryCostCenter::factory()->create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
        ]);

        $department->delete();

        $this->actingAs($this->admin())
            ->get(route('admin.university.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('signatories.0.cost_centers.0.name', 'Кафедра ІМЗД'));
    }

    public function test_an_admin_can_add_a_pair_ahead_of_any_order(): void
    {
        $signatory = UniversityRef::factory()->create();
        $department = Department::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.university.signatories.cost-centers.store', $signatory->id), [
                'department_id' => $department->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('signatory_cost_centers', [
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
            'orders_count'      => 0,
        ]);
    }

    public function test_adding_the_same_pair_twice_does_not_duplicate_it(): void
    {
        $signatory = UniversityRef::factory()->create();
        $department = Department::factory()->create();

        SignatoryCostCenter::factory()->create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
            'orders_count'      => 5,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.university.signatories.cost-centers.store', $signatory->id), [
                'department_id' => $department->id,
            ]);

        $this->assertSame(1, SignatoryCostCenter::count());
        $this->assertSame(5, SignatoryCostCenter::first()->orders_count);
    }

    public function test_an_admin_can_remove_a_wrong_pair(): void
    {
        $signatory = UniversityRef::factory()->create();
        $department = Department::factory()->create();

        SignatoryCostCenter::factory()->create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.university.signatories.cost-centers.destroy', [$signatory->id, $department->id]))
            ->assertRedirect();

        $this->assertSame(0, SignatoryCostCenter::count());
    }

    /**
     * `index()` lists signatories `withTrashed()` and never hides the «Центри
     * витрат» button for a deactivated one, so this screen has to reach a
     * deactivated signatory too — not 404 on the implicit binding the way a
     * plain `{signatory}` route parameter would.
     */
    public function test_an_admin_can_add_a_pair_for_a_deactivated_signatory(): void
    {
        $signatory = UniversityRef::factory()->create();
        $signatory->delete();
        $department = Department::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.university.signatories.cost-centers.store', $signatory->id), [
                'department_id' => $department->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('signatory_cost_centers', [
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
        ]);
    }

    public function test_an_admin_can_add_a_pair_naming_a_deactivated_department(): void
    {
        $signatory = UniversityRef::factory()->create();
        $department = Department::factory()->create();
        $department->delete();

        $this->actingAs($this->admin())
            ->post(route('admin.university.signatories.cost-centers.store', $signatory->id), [
                'department_id' => $department->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('signatory_cost_centers', [
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
        ]);
    }

    /**
     * Removing a wrong pair is exactly the case that needs to reach a
     * deactivated signatory: nothing stops an admin from deactivating the
     * signatory first and cleaning up their pairs after.
     */
    public function test_an_admin_can_remove_a_pair_from_a_deactivated_signatory(): void
    {
        $signatory = UniversityRef::factory()->create();
        $department = Department::factory()->create();

        SignatoryCostCenter::factory()->create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
        ]);

        $signatory->delete();

        $this->actingAs($this->admin())
            ->delete(route('admin.university.signatories.cost-centers.destroy', [$signatory->id, $department->id]))
            ->assertRedirect();

        $this->assertSame(0, SignatoryCostCenter::count());
    }

    public function test_an_admin_can_remove_a_pair_naming_a_deactivated_department(): void
    {
        $signatory = UniversityRef::factory()->create();
        $department = Department::factory()->create();

        SignatoryCostCenter::factory()->create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
        ]);

        $department->delete();

        $this->actingAs($this->admin())
            ->delete(route('admin.university.signatories.cost-centers.destroy', [$signatory->id, $department->id]))
            ->assertRedirect();

        $this->assertSame(0, SignatoryCostCenter::count());
    }

    /**
     * URL із парою, якої немає, мусить діставати 404 — а не «Центр витрат
     * відв'язано» про видалення, якого не сталося. Сусідній
     * destroyInitiator так і поводиться; це та сама кнопка.
     */
    public function test_unlinking_a_pair_that_is_not_there_is_a_404_not_a_success(): void
    {
        $signatory = UniversityRef::factory()->create();
        $department = Department::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.university.signatories.cost-centers.destroy', [$signatory->id, $department->id]))
            ->assertNotFound();
    }
}
