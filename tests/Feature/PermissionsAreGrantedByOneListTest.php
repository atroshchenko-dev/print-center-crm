<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A module you can grant is a module that opens something.
 *
 * `users` sat in the permission list for the whole life of the project without
 * a single route ever asking for it. `/admin/users` is `role:admin` on purpose
 * — the module would let its holder create an account with role=admin and a
 * password of their choosing — but the checkbox was still offered on the users
 * page, the sidebar still drew «Користувачі» for anyone holding it, and the
 * footer badge still counted it towards their access. The one thing it could do
 * was promise a screen and answer 403.
 *
 * Nothing failed, because every copy of the list was consistent with itself.
 * `PermissionWorkflowTest::test_executor_without_users_gets_403` even reads as
 * proof of the opposite: it pins what happens *without* the module, and an
 * executor *with* it would have got the same 403.
 *
 * These are set comparisons rather than a list of expected keys on purpose. A
 * list would be an eighth copy — and the class this test exists for is exactly
 * that.
 */
class PermissionsAreGrantedByOneListTest extends TestCase
{
    /**
     * The grantable modules and the modules the route table gates on are the
     * same set — in both directions.
     *
     * Left to right: a module nobody can be given cannot guard a page, or the
     * page is unreachable by anyone but an admin without saying so. Right to
     * left: a module that gates nothing is a promise with no screen behind it.
     */
    public function test_every_grantable_module_gates_a_route_and_back(): void
    {
        $gated = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    $gated[] = substr($middleware, strlen('permission:'));
                }
            }
        }

        $gated = array_values(array_unique($gated));
        sort($gated);

        $grantable = User::permissionKeys();
        sort($grantable);

        $this->assertSame(
            $grantable,
            $gated,
            "The grantable modules and the modules the routes gate on have drifted apart.\n"
                .'Grantable: '.implode(', ', $grantable)."\n"
                .'Gating a route: '.implode(', ', $gated),
        );
    }

    /**
     * Every module the sidebar and the dashboard ask about is one that exists.
     *
     * Source assertions, in the style `FrontendRouteWiringTest` established and
     * for the same reason: a `v-if` naming a module nobody can hold draws
     * nothing and throws nothing, so no request-level test can see it. Here the
     * failure ran the other way — `can('users')` was true for a module the
     * server had stopped honouring — but one comparison catches both.
     */
    public function test_the_frontend_only_asks_about_modules_that_exist(): void
    {
        $known = User::permissionKeys();
        $unknown = [];

        $sources = [
            'js/Layouts/AppLayout.vue',
            'js/Pages/Dashboard.vue',
        ];

        foreach ($sources as $relative) {
            $source = (string) file_get_contents(resource_path($relative));
            preg_match_all("/\bcan\(\s*'([^']+)'\s*\)/", $source, $matches);

            foreach (array_unique($matches[1]) as $module) {
                if (! in_array($module, $known, true)) {
                    $unknown[] = $module.'  ← '.$relative;
                }
            }
        }

        $this->assertSame(
            [],
            $unknown,
            "The frontend gates on modules that are not grantable:\n".implode("\n", $unknown),
        );
    }

    /**
     * An admin's default permissions are the list, not a copy of it.
     *
     * The copy that used to stand in `UserRole::Admin->defaultPermissions()`
     * still carried `users` — so every admin created since has the dead key in
     * their column, and all three in production do.
     */
    public function test_an_admin_is_given_exactly_the_grantable_modules(): void
    {
        $this->assertSame(
            User::permissionKeys(),
            UserRole::Admin->defaultPermissions(),
        );
    }

    /**
     * `users` specifically, named, because it is the one that was wrong and the
     * one somebody will be tempted to put back.
     */
    public function test_users_is_not_a_grantable_module(): void
    {
        $this->assertNotContains(
            'users',
            User::permissionKeys(),
            '/admin/users is role:admin — the module granted nothing and drew a link that answered 403',
        );
    }
}
