<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A parameter group has to be editable, because its name decides behaviour.
 *
 * The update endpoint was routed and validated from the start and called from
 * nowhere: Services/Form.vue could add a group and delete a group, nothing
 * else. So the only way to fix a typo in a group name was to delete the group
 * and build it again — and that mints a new id, drops every option under it,
 * and orphans every depends_on pointing at those options.
 *
 * This is R3-18 exactly, one level up and in the same file: that round added
 * option editing for this reason and left the group above it alone. It matters
 * more here, not less. ServiceParameterGroup::PAPER keys the customer-paper
 * rule on the literal string 'Тип паперу', and its own docblock
 * says renaming the group "in the admin UI" silently disables the rule — a
 * thing the admin UI could not do.
 */
class ServiceParameterGroupEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin   = User::factory()->admin()->create();
        $this->service = Service::factory()->create(['type' => 'constructor']);
    }

    public function test_the_service_form_can_reach_the_group_update_endpoint(): void
    {
        $source = (string) file_get_contents(resource_path('js/Pages/Admin/Services/Form.vue'));

        $this->assertTrue(
            str_contains($source, "route('admin.service-groups.update'"),
            'The form can add and delete a group but not edit one, so a rename means delete and rebuild.',
        );
    }

    public function test_an_admin_can_rename_a_group(): void
    {
        $group = ServiceParameterGroup::factory()->create([
            'service_id' => $this->service->id,
            'name'       => 'Тип паперy',   // the typo this endpoint exists for
            'ui_type'    => 'radio',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-groups.update', $group), [
                'name'        => ServiceParameterGroup::PAPER,
                'ui_type'     => 'radio',
                'is_required' => true,
                'sort_order'  => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(ServiceParameterGroup::PAPER, $group->fresh()->name);
    }

    public function test_renaming_a_group_keeps_its_options_and_their_ids(): void
    {
        $group = ServiceParameterGroup::factory()->create([
            'service_id' => $this->service->id,
            'name'       => 'Формат',
            'ui_type'    => 'radio',
        ]);
        $option = ServiceParameterOption::factory()->create([
            'group_id' => $group->id,
            'name'     => 'А4',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-groups.update', $group), [
                'name'        => 'Формат аркуша',
                'ui_type'     => 'radio',
                'is_required' => true,
                'sort_order'  => 0,
            ]);

        // The whole point: the id survives, so anything pointing at it survives.
        // Delete-and-rebuild is what did not.
        $this->assertSame(1, $group->options()->count());
        $this->assertSame($option->id, $group->options()->first()->id);
    }

    public function test_renaming_a_group_does_not_clear_a_dependency_it_was_not_asked_about(): void
    {
        $parent = ServiceParameterGroup::factory()->create([
            'service_id' => $this->service->id,
            'name'       => 'Формат',
        ]);
        $parentOption = ServiceParameterOption::factory()->create(['group_id' => $parent->id]);

        $child = ServiceParameterGroup::factory()->create([
            'service_id' => $this->service->id,
            'name'       => 'Плівка',
            'ui_type'    => 'radio',
            'depends_on' => ['group_id' => $parent->id, 'option_ids' => [$parentOption->id]],
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-groups.update', $child), [
                'name'        => 'Тип плівки',
                'ui_type'     => 'radio',
                'is_required' => true,
                'sort_order'  => 1,
            ]);

        $fresh = $child->fresh();
        $this->assertSame('Тип плівки', $fresh->name);
        $this->assertSame(
            ['group_id' => $parent->id, 'option_ids' => [$parentOption->id]],
            $fresh->depends_on,
            'depends_on is not on the edit form, so it must not be submitted and must not be wiped.',
        );
    }

    public function test_a_user_without_the_services_module_cannot_rename_a_group(): void
    {
        $outsider = User::factory()->create(['role' => 'executor', 'permissions' => []]);
        $group = ServiceParameterGroup::factory()->create(['service_id' => $this->service->id]);

        $this->actingAs($outsider)
            ->patch(route('admin.service-groups.update', $group), [
                'name'    => 'Зламано',
                'ui_type' => 'radio',
            ])
            ->assertForbidden();

        $this->assertNotSame('Зламано', $group->fresh()->name);
    }
}
