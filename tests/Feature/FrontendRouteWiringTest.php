<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The frontend against the route table.
 *
 * Round 10 swept this boundary by hand and it produced four findings; round 12
 * found two buttons calling `admin.equipment.force-destroy` and
 * `admin.materials.force-destroy`, names that have never existed on the server.
 * Ziggy throws into the console and the button does nothing visible, so no
 * endpoint test can catch it — only a comparison of the two sides can.
 *
 * These are source assertions on purpose. They are brittle to refactoring, and
 * that is the accepted price: the class they guard has now fired seventeen
 * times across twelve rounds.
 */
class FrontendRouteWiringTest extends TestCase
{
    public function test_every_route_name_the_frontend_calls_exists_on_the_server(): void
    {
        $registered = array_keys(Route::getRoutes()->getRoutesByName());

        $missing = [];
        foreach ($this->vueSources() as $path => $source) {
            preg_match_all('/route\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);

            foreach (array_unique($matches[1]) as $name) {
                if (! in_array($name, $registered, true)) {
                    $missing[] = $name . '  ← ' . $path;
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "The frontend calls route names the server does not register:\n"
                . implode("\n", $missing),
        );
    }

    public function test_the_dashboard_gates_the_actions_it_offers(): void
    {
        // Every user lands here after login, whatever their permissions, so the
        // three quick-action cards must ask the same question the sidebar has
        // always asked. Without it an accountant holding only `reports` was
        // shown three inviting cards that each answered 403.
        $source = (string) file_get_contents(resource_path('js/Pages/Dashboard.vue'));

        $this->assertStringContainsString("can('orders')", $source);
        $this->assertStringContainsString("can('ledger')", $source);
    }

    /**
     * @return array<string, string> path => contents
     */
    private function vueSources(): array
    {
        $root  = resource_path('js');
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        $this->assertNotEmpty($files, 'No .vue sources found — the sweep would pass vacuously.');

        return $files;
    }
}
