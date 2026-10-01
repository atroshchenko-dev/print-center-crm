<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CostCenterInitiator;
use App\Models\Department;
use App\Models\User;
use App\Services\ReferenceDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Мапа «підрозділ → його ініціатори», якою форма звужує підказки.
 * Порядок у мапі — частина контракту: найчастіші йдуть першими.
 */
class AdminCostCenterInitiatorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_map_puts_the_most_frequent_name_first(): void
    {
        $department = Department::factory()->create(['name' => 'Кафедра туризму']);

        CostCenterInitiator::factory()->create([
            'department_id' => $department->id,
            'name'          => 'Рідкісна Р.Р.',
            'name_key'      => CostCenterInitiator::normalizeKey('Рідкісна Р.Р.'),
            'orders_count'  => 2,
        ]);
        CostCenterInitiator::factory()->create([
            'department_id' => $department->id,
            'name'          => 'Часта Ч.Ч.',
            'name_key'      => CostCenterInitiator::normalizeKey('Часта Ч.Ч.'),
            'orders_count'  => 40,
        ]);

        $map = app(ReferenceDataService::class)->costCenterInitiators();

        $this->assertSame(['Часта Ч.Ч.', 'Рідкісна Р.Р.'], $map[$department->id]);
    }

    public function test_a_soft_deleted_centre_leaves_the_map(): void
    {
        $department = Department::factory()->create();

        CostCenterInitiator::factory()->create([
            'department_id' => $department->id,
            'name'          => 'Іванова О.В.',
            'name_key'      => CostCenterInitiator::normalizeKey('Іванова О.В.'),
        ]);

        $department->delete();

        $map = app(ReferenceDataService::class)->costCenterInitiators();

        $this->assertArrayNotHasKey($department->id, $map->all());
    }

    /**
     * `deleted_at` and `is_active` are two independent conditions on the same
     * join, ANDed together — a department can be inactive without being
     * soft-deleted (the admin "деактивувати" toggle, distinct from
     * «Видалити»). Each needs its own test, or dropping one condition from
     * the query would go unnoticed.
     */
    public function test_an_inactive_but_not_deleted_centre_leaves_the_map(): void
    {
        $department = Department::factory()->create(['is_active' => false]);

        CostCenterInitiator::factory()->create([
            'department_id' => $department->id,
            'name'          => 'Іванова О.В.',
            'name_key'      => CostCenterInitiator::normalizeKey('Іванова О.В.'),
        ]);

        $map = app(ReferenceDataService::class)->costCenterInitiators();

        $this->assertArrayNotHasKey($department->id, $map->all());
    }

    /**
     * Перейменування підрозділу лишало б мапу стухлою — рівно той сценарій,
     * який фінальне рев'ю попереднього раунду знайшло для пар підписанта.
     */
    public function test_renaming_a_department_drops_the_initiator_map(): void
    {
        $department = Department::factory()->create(['name' => 'Стара назва']);

        CostCenterInitiator::factory()->create([
            'department_id' => $department->id,
            'name'          => 'Іванова О.В.',
            'name_key'      => CostCenterInitiator::normalizeKey('Іванова О.В.'),
        ]);

        app(ReferenceDataService::class)->costCenterInitiators();
        $this->assertTrue(Cache::has('ref:cost_center_initiators'));

        $department->update(['name' => 'Нова назва']);

        $this->assertFalse(Cache::has('ref:cost_center_initiators'));
    }

    public function test_flush_drops_the_initiator_key(): void
    {
        Cache::put('ref:cost_center_initiators', collect(), 60);

        app(ReferenceDataService::class)->flush();

        $this->assertFalse(Cache::has('ref:cost_center_initiators'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * `name` and `orders_count` alone don't pin the guard: `CostCenterInitiator`
     * carries both as native top-level columns, so the raw relation model would
     * serialise them identically even with `unsetRelation()`/`setAttribute()`
     * deleted outright. `last_used_at` does distinguish the two — the mapped
     * value is `->toDateString()`, a full timestamp is what the raw cast
     * produces — and `name_key` only ever appears on the raw model, never in
     * the mapped array, so its absence is equally load-bearing.
     */
    public function test_the_page_shows_each_centre_with_its_initiators(): void
    {
        $department = Department::factory()->create(['name' => 'Кафедра туризму']);

        CostCenterInitiator::factory()->create([
            'department_id' => $department->id,
            'name'          => 'Іванова О.В.',
            'name_key'      => CostCenterInitiator::normalizeKey('Іванова О.В.'),
            'orders_count'  => 7,
            'last_used_at'  => '2026-08-15 14:30:00',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.university.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('departments.0.initiators.0.name', 'Іванова О.В.')
                ->where('departments.0.initiators.0.orders_count', 7)
                ->where('departments.0.initiators.0.last_used_at', '2026-08-15')
                ->missing('departments.0.initiators.0.name_key'));
    }

    public function test_an_admin_can_add_a_name_ahead_of_any_order(): void
    {
        $department = Department::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.university.departments.initiators.store', $department->id), [
                'initiator' => 'Новенька Н.Н.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('cost_center_initiators', [
            'department_id' => $department->id,
            'name'          => 'Новенька Н.Н.',
            'orders_count'  => 0,
        ]);
    }

    public function test_adding_the_same_name_twice_does_not_duplicate_it(): void
    {
        $department = Department::factory()->create();

        CostCenterInitiator::factory()->create([
            'department_id' => $department->id,
            'name'          => 'Іванова О.В.',
            'name_key'      => CostCenterInitiator::normalizeKey('Іванова О.В.'),
            'orders_count'  => 5,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.university.departments.initiators.store', $department->id), [
                'initiator' => 'іванова о.в.',
            ]);

        $this->assertSame(1, CostCenterInitiator::count());
        $this->assertSame(5, CostCenterInitiator::first()->orders_count);
    }

    public function test_an_admin_can_remove_a_wrong_name(): void
    {
        $department = Department::factory()->create();

        $pair = CostCenterInitiator::factory()->create([
            'department_id' => $department->id,
            'name'          => 'Помилкова П.П.',
            'name_key'      => CostCenterInitiator::normalizeKey('Помилкова П.П.'),
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.university.departments.initiators.destroy', [$department->id, $pair->id]))
            ->assertRedirect();

        $this->assertSame(0, CostCenterInitiator::count());
    }

    public function test_a_deactivated_centre_can_still_be_cleaned_up(): void
    {
        $department = Department::factory()->create();

        $pair = CostCenterInitiator::factory()->create([
            'department_id' => $department->id,
            'name'          => 'Помилкова П.П.',
            'name_key'      => CostCenterInitiator::normalizeKey('Помилкова П.П.'),
        ]);

        $department->delete();

        $this->actingAs($this->admin())
            ->delete(route('admin.university.departments.initiators.destroy', [$department->id, $pair->id]))
            ->assertRedirect();

        $this->assertSame(0, CostCenterInitiator::count());
    }
}
