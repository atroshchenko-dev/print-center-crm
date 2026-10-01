<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SignatoryGroup;
use App\Models\UniversityRef;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * «Видалити» on this page is a soft delete, and the page was written to say so:
 * a deactivated row is greyed, badged «Видалено» and loses its buttons. None of
 * that could ever render — the three index queries carried the soft-delete
 * scope, so the row simply disappeared and the admin was left with a message
 * saying «деактивовано» and nothing to see it on.
 *
 * The `activeGroups` computed on the page filters `!g.deleted_at` to build the
 * signatory dropdown, which is only meaningful if such rows arrive at all.
 */
class UniversityDeactivatedRowsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role'        => 'admin',
            'permissions' => User::permissionKeys(),
        ]);
    }

    public function test_a_deactivated_group_still_appears_on_the_page(): void
    {
        $group = SignatoryGroup::factory()->create(['name' => 'Деканат']);

        $this->actingAs($this->admin)
            ->delete(route('admin.university.groups.destroy', $group))
            ->assertRedirect();

        $this->assertSoftDeleted('signatory_groups', ['id' => $group->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.university.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('signatoryGroups', 1)
                ->where('signatoryGroups.0.name', 'Деканат')
                ->whereNot('signatoryGroups.0.deleted_at', null));
    }

    public function test_a_deactivated_signatory_still_appears_on_the_page(): void
    {
        $signatory = UniversityRef::factory()->create(['full_name' => 'Іваненко Іван Іванович']);

        $this->actingAs($this->admin)
            ->delete(route('admin.university.signatories.destroy', $signatory))
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('admin.university.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('signatories', 1)
                ->where('signatories.0.full_name', 'Іваненко Іван Іванович')
                ->whereNot('signatories.0.deleted_at', null));
    }

    public function test_a_deactivated_department_still_appears_on_the_page(): void
    {
        $department = Department::create(['name' => 'Кафедра права', 'type' => 'department', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->delete(route('admin.university.departments.destroy', $department))
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('admin.university.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('departments', 1)
                ->whereNot('departments.0.deleted_at', null));
    }

    /**
     * A signatory whose group was deactivated is not ungrouped — and the page
     * prints «Без групи» for a null relation, which is a different fact.
     */
    public function test_a_signatory_keeps_the_name_of_a_deactivated_group(): void
    {
        $group = SignatoryGroup::factory()->create(['name' => 'Деканат']);
        UniversityRef::factory()->create([
            'full_name'          => 'Іваненко Іван Іванович',
            'signatory_group_id' => $group->id,
        ]);

        $group->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.university.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('signatories.0.group.name', 'Деканат'));
    }
}
