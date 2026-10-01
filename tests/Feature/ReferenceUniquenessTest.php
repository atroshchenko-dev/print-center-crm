<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Material;
use App\Models\UniversityRef;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * One live row per name, and one active material per counter type.
 *
 * Every rule that spends a quota looks its reference up by the string on the
 * order — that is the denormalisation the whole project rests on.
 * It only works while a name means one row. Round 19 removed the auto-save that
 * forked rows behind a soft delete; entering a twin by hand stayed possible, and
 * so did the case-only twin production actually holds: «Кафедра ІМЗД» live,
 * «кафедра ІМЗД» deleted.
 *
 * The index is what makes it true. These tests are about the other half — that
 * the collision arrives as a sentence under the field rather than as a 500, and
 * that the paths which write a reference row *without* a form (the cost-centre
 * auto-save) do not walk into the constraint.
 */
class ReferenceUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    // ─── Cost centres ────────────────────────────────────

    public function test_a_department_name_cannot_be_taken_twice(): void
    {
        Department::create(['name' => 'Кафедра ІМЗД', 'type' => 'department', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->post(route('admin.university.departments.store'), [
                'name'      => 'Кафедра ІМЗД',
                'type'      => 'department',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Department::count());
    }

    /**
     * The pair production actually holds. Comparing raw strings is what let the
     * second one in, and `where('name', …)` is case-sensitive in PostgreSQL —
     * so the two rows were two different cost centres to every limit rule.
     */
    public function test_a_department_name_differing_only_in_case_is_the_same_name(): void
    {
        Department::create(['name' => 'Кафедра ІМЗД', 'type' => 'department', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->post(route('admin.university.departments.store'), [
                'name'      => '  кафедра імзд  ',
                'type'      => 'department',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Department::count());
    }

    /**
     * A deleted row keeps its name for the journal, so the index is partial —
     * and the name it holds is free to be used again.
     */
    public function test_a_deleted_department_does_not_block_the_name(): void
    {
        $department = Department::create(['name' => 'Кафедра права', 'type' => 'department', 'is_active' => true]);
        $department->delete();

        $this->actingAs($this->admin)
            ->post(route('admin.university.departments.store'), [
                'name'      => 'Кафедра права',
                'type'      => 'department',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Department::count());
        $this->assertSame(2, Department::withTrashed()->count());
    }

    /**
     * The reference page posts the whole row on every edit, including
     * deactivations — so saving a row without touching its name must not
     * collide with itself.
     */
    public function test_saving_a_department_without_renaming_it_is_not_a_collision(): void
    {
        $department = Department::create(['name' => 'Ректорат', 'type' => 'department', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->patch(route('admin.university.departments.update', $department), [
                'name'      => 'Ректорат',
                'type'      => 'department',
                'is_active' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($department->fresh()->is_active);
    }

    /**
     * `Department::remember()` writes without a form — from both order paths,
     * both retro paths and the retro import. After the index, an exact-match
     * lookup would miss the case-only twin, try to insert, and take an
     * unrelated order save down with a constraint violation.
     */
    public function test_the_cost_centre_auto_save_does_not_walk_into_the_index(): void
    {
        Department::create(['name' => 'Кафедра ІМЗД', 'type' => 'department', 'is_active' => true]);

        Department::remember('кафедра імзд');
        Department::remember('  Кафедра ІМЗД  ');

        $this->assertSame(1, Department::withTrashed()->count(), 'one department, however it was typed');
    }

    // ─── Signatories ─────────────────────────────────────

    public function test_a_signatory_name_cannot_be_taken_twice(): void
    {
        UniversityRef::factory()->create(['full_name' => 'Іваненко Іван Іванович', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->post(route('admin.university.signatories.store'), [
                'full_name' => 'іваненко іван іванович',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('full_name');

        $this->assertSame(1, UniversityRef::count());
    }

    /**
     * Production holds two deleted «Марунова О.О.». A full index would have
     * rejected them, and deleting them to please it would erase what an order
     * says about who signed for it.
     *
     * The order of operations here **is** the finding. Writing this test the
     * obvious way — create both, then delete both — fails, and correctly: two
     * live twins are exactly what the index exists to stop. The only way to
     * reach the state production is in is one at a time, each deleted before
     * the next is entered. Which is also how it happened: a row entered,
     * removed, entered again, removed again.
     */
    public function test_two_deleted_signatories_may_share_a_name(): void
    {
        UniversityRef::factory()->create(['full_name' => 'Марунова О.О.'])->delete();
        UniversityRef::factory()->create(['full_name' => 'Марунова О.О.'])->delete();

        $this->assertSame(2, UniversityRef::withTrashed()->where('full_name', 'Марунова О.О.')->count());
        $this->assertSame(0, UniversityRef::where('full_name', 'Марунова О.О.')->count());
    }

    // ─── Materials ───────────────────────────────────────

    /**
     * Owner's decision, 2026-08-01 (CLOSEOUT §1.8): not "which of the two
     * wins", but "there is only ever one".
     */
    public function test_a_second_active_material_of_one_counter_type_is_refused(): void
    {
        Material::factory()->create(['counter_type' => 'bw', 'click_cost' => 0.37, 'is_active' => true]);

        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), [
                'name'         => 'Ч/Б друк (нове покриття)',
                'counter_type' => 'bw',
                'click_cost'   => 0.45,
                'is_active'    => true,
            ])
            ->assertSessionHasErrors('counter_type');

        $this->assertSame(1, Material::where('counter_type', 'bw')->count());
    }

    /** A deactivated material is history, and the index does not count it. */
    public function test_a_second_material_may_be_added_deactivated(): void
    {
        Material::factory()->create(['counter_type' => 'bw', 'click_cost' => 0.37, 'is_active' => true]);

        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), [
                'name'         => 'Ч/Б друк (наступне покриття)',
                'counter_type' => 'bw',
                'click_cost'   => 0.45,
                'is_active'    => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Material::where('counter_type', 'bw')->count());
    }

    /**
     * Deactivating the only active material is the one road to «nothing
     * active» that survives the index — so it is the road §1.8-bis has to
     * answer for. What the price does then is `MaterialClickCostTest`; that it
     * can still be reached from the admin page is this.
     */
    public function test_the_last_active_material_can_still_be_retired(): void
    {
        $material = Material::factory()->create(['counter_type' => 'bw', 'click_cost' => 0.37, 'is_active' => true]);

        $this->actingAs($this->admin)
            ->patch(route('admin.materials.update', $material), [
                'name'         => $material->name,
                'counter_type' => 'bw',
                'click_cost'   => 0.37,
                'is_active'    => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($material->fresh()->is_active);
        $this->assertSame(0.37, Material::clickCosts()['bw'], 'the retired row is still the last known price');
    }
}
