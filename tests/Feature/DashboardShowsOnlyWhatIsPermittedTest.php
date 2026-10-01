<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard offers a card only to somebody the card would work for.
 *
 * `Dashboard.vue` gates «Нове замовлення» and «Замовлення зміни» on
 * `can('orders')` and «Каса» on `can('ledger')`, and `can()` reads the
 * permissions `HandleInertiaRequests` shares. Nothing asserted what a user
 * *without* those permissions is handed, so the guard rested on one expression
 * nobody exercised — the shape that produced R12-2 and the five unreachable
 * actions counted in §1 of the registry.
 *
 * **This exists because the check could not be done any other way.** It stood
 * in CLOSEOUT §3 as item 4, «дашборд не-адміна з частковими правами», waiting
 * on a second account — and a second account cannot be created by the audit:
 * it needs a password, and passwords are the owner's to type. Waiting for one
 * was the only plan for eleven rounds. A test asks the same question of the
 * same expression and needs nobody (audit round 26).
 *
 * What it does not cover, stated so nobody assumes otherwise: that the Vue
 * `v-if` is spelled correctly. That half is `FrontendRouteWiringTest`'s and the
 * silent-forms baseline's territory — this one pins the data those directives
 * read.
 */
class DashboardShowsOnlyWhatIsPermittedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A manager holding only `reports` is told so, and the two cards that would
     * bounce them are therefore never drawn.
     */
    public function test_a_manager_without_orders_or_ledger_is_shared_neither(): void
    {
        $manager = User::factory()->create([
            'role'        => UserRole::Manager,
            'permissions' => ['reports'],
        ]);

        $permissions = $this->permissionsSharedWith($manager);

        $this->assertSame(['reports'], $permissions);
        $this->assertNotContains('orders', $permissions, 'the two order cards would 403 for this user');
        $this->assertNotContains('ledger', $permissions, 'the cash card needs an open till and the ledger module');
    }

    /**
     * The other half of the same expression: an executor holding both gets both.
     *
     * Without this the assertion above would pass just as happily against a
     * share that returned nothing to anybody.
     */
    public function test_an_executor_holding_both_is_shared_both(): void
    {
        $executor = User::factory()->create([
            'role'        => UserRole::Executor,
            'permissions' => ['orders', 'ledger'],
        ]);

        $permissions = $this->permissionsSharedWith($executor);

        $this->assertContains('orders', $permissions);
        $this->assertContains('ledger', $permissions);
    }

    /**
     * An admin bypasses the column entirely, and that is deliberate.
     *
     * `HandleInertiaRequests` hands an admin `User::permissionKeys()` rather than
     * whatever the JSONB column happens to hold — so an admin whose column is
     * empty still sees every card. Pinned because the bypass is easy to
     * "tidy up" into reading the column, which would blank the admin dashboard.
     */
    public function test_an_admin_with_an_empty_column_still_gets_everything(): void
    {
        $admin = User::factory()->create([
            'role'        => UserRole::Admin,
            'permissions' => [],
        ]);

        $this->assertSame(User::permissionKeys(), $this->permissionsSharedWith($admin));
    }

    /**
     * @return array<int, string>
     */
    private function permissionsSharedWith(User $user): array
    {
        // Without one the dashboard redirects to the shift-open form and never
        // renders, which is the behaviour `canEditOrders` leans on elsewhere —
        // see ReconciliationController.
        Shift::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        return $response->viewData('page')['props']['auth']['user']['permissions'];
    }
}
