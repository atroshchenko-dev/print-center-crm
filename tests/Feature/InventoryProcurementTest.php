<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The procurement prop is deferred: absent from the first render, served by
 * the follow-up partial request the Inertia client sends on its own.
 */
class InventoryProcurementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /** Simulate the deferred-prop fetch the Inertia client performs. */
    private function fetchDeferred(User $user, array $query = []): TestResponse
    {
        $version = (string) app(HandleInertiaRequests::class)->version(Request::create('/'));

        return $this->actingAs($user)->get(route('admin.inventory.index', $query), [
            'X-Inertia'                   => 'true',
            'X-Inertia-Version'           => $version,
            'X-Inertia-Partial-Component' => 'Admin/Inventory/Index',
            'X-Inertia-Partial-Data'      => 'procurement',
        ]);
    }

    public function test_first_render_defers_the_procurement_prop(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.inventory.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Inventory/Index')
                ->missing('procurement'));
    }

    public function test_deferred_fetch_returns_the_computed_payload(): void
    {
        $response = $this->fetchDeferred($this->admin);

        $response->assertOk();
        $payload = $response->json('props.procurement');

        $this->assertSame(60, $payload['horizon_days']);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('regular', $payload);
        $this->assertArrayHasKey('pairs', $payload);
        $this->assertArrayHasKey('toners', $payload);
    }

    public function test_horizon_is_validated_to_the_allowed_set(): void
    {
        $this->assertSame(90, $this->fetchDeferred($this->admin, ['horizon' => 90])->json('props.procurement.horizon_days'));
        $this->assertSame(60, $this->fetchDeferred($this->admin, ['horizon' => 45])->json('props.procurement.horizon_days'));
        $this->assertSame(60, $this->fetchDeferred($this->admin, ['horizon' => 'junk'])->json('props.procurement.horizon_days'));
    }

    public function test_array_horizon_is_rejected_not_a_crash(): void
    {
        $this->assertSame(
            60,
            $this->fetchDeferred($this->admin, ['horizon' => ['30']])->json('props.procurement.horizon_days'),
        );
    }

    public function test_executor_without_inventory_permission_is_forbidden(): void
    {
        $executor = User::factory()->create(['role' => 'executor']);

        $this->fetchDeferred($executor)->assertForbidden();
    }
}
