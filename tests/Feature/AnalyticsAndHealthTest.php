<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two modules that had no tests at all: the analytics page and the health
 * endpoint. Both turned out to be telling somebody something wrong.
 */
class AnalyticsAndHealthTest extends TestCase
{
    use RefreshDatabase;

    private function analytics(User $user): array
    {
        $response = $this->actingAs($user)->get(route('analytics'));
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    /**
     * Every other figure on the analytics page excludes cancelled orders.
     * The service ranking did not, so work that was never done pushed a
     * service up the list.
     */
    public function test_cancelled_orders_do_not_inflate_the_service_ranking(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $live = Order::factory()->create([
            'user_id' => $admin->id,
            'status'  => OrderStatus::CompletedIssued->value,
        ]);
        OrderItem::factory()->create([
            'order_id'     => $live->id,
            'service_name' => 'Друк А4',
            'quantity'     => 10,
        ]);

        $cancelled = Order::factory()->create([
            'user_id' => $admin->id,
            'status'  => OrderStatus::Cancelled->value,
        ]);
        OrderItem::factory()->create([
            'order_id'     => $cancelled->id,
            'service_name' => 'Друк А4',
            'quantity'     => 990,
        ]);

        $top = $this->analytics($admin)['topServices'];

        $this->assertCount(1, $top);
        $this->assertSame('Друк А4', $top[0]['service_name']);
        $this->assertEquals(
            10,
            $top[0]['total_qty'],
            'Only the order that was actually produced counts.',
        );
    }

    /**
     * Operator productivity was grouped by users.name. Two people with the
     * same name became one row, and one got credit for the other's work.
     */
    public function test_two_operators_sharing_a_name_are_counted_separately(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Адміністратор']);

        $first  = User::factory()->create(['name' => 'Олена Ковальчук']);
        $second = User::factory()->create(['name' => 'Олена Ковальчук']);

        Order::factory()->count(2)->create(['user_id' => $first->id]);
        Order::factory()->create(['user_id' => $second->id]);

        $stats = $this->analytics($admin)['operatorStats'];

        $rows = array_values(array_filter(
            $stats,
            fn ($row) => $row['name'] === 'Олена Ковальчук',
        ));

        $this->assertCount(2, $rows, 'Two operators, two rows — the name is not the identity.');
        $this->assertEqualsCanonicalizing([2, 1], array_column($rows, 'order_count'));
    }

    /**
     * The range comes in as Kyiv days converted to UTC, and the chart cut the
     * buckets with DATE(created_at) — a UTC date. An order taken at 01:00 Kyiv
     * was drawn on the previous day's point, the same disagreement the
     * dashboard chart had.
     */
    public function test_an_order_taken_after_midnight_kyiv_lands_on_that_kyiv_day(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Order::factory()->create([
            'user_id' => $admin->id,
            'status'  => OrderStatus::New->value,
            // ->utc() matters: a Kyiv-zoned Carbon is written with its own wall
            // clock, which would store 01:00 as if it were UTC and quietly
            // remove the very disagreement under test.
            'created_at' => Carbon::parse('2026-07-15 01:00:00', 'Europe/Kyiv')->utc(),
        ]);

        $this->assertSame(
            '2026-07-14',
            Order::first()->created_at->toDateString(),
            'Precondition: in UTC this order belongs to the previous date.',
        );

        $response = $this->actingAs($admin)->get(route('analytics', [
            'from' => '2026-07-15',
            'to'   => '2026-07-15',
        ]));
        $response->assertOk();

        $perDay = $response->viewData('page')['props']['ordersPerDay'];

        $this->assertCount(1, $perDay, 'The order is inside the requested Kyiv day.');
        $this->assertSame('2026-07-15', substr((string) $perDay[0]['date'], 0, 10));
        $this->assertEquals(1, $perDay[0]['count']);
    }

    /**
     * Owner's decision, 2026-07-28: the daily chart shows work done, not work
     * taken in. Every other figure on the page already meant that; this one
     * counted cancellations and drew a taller line than the day deserved.
     */
    public function test_a_cancelled_order_is_not_a_days_work(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Order::factory()->create([
            'user_id'    => $admin->id,
            'status'     => OrderStatus::CompletedIssued->value,
            'created_at' => Carbon::parse('2026-07-15 10:00:00', 'Europe/Kyiv')->utc(),
        ]);

        Order::factory()->create([
            'user_id'    => $admin->id,
            'status'     => OrderStatus::Cancelled->value,
            'created_at' => Carbon::parse('2026-07-15 11:00:00', 'Europe/Kyiv')->utc(),
        ]);

        $response = $this->actingAs($admin)->get(route('analytics', [
            'from' => '2026-07-15',
            'to'   => '2026-07-15',
        ]));
        $response->assertOk();

        $perDay = $response->viewData('page')['props']['ordersPerDay'];

        $this->assertCount(1, $perDay);
        $this->assertEquals(1, $perDay[0]['count'], 'Only the order that was produced counts.');
    }

    /**
     * The third figure on this page to be caught counting cancellations, after
     * the service ranking and the daily chart. Productivity is
     * the ranking of who did the work, and it credited an operator for orders
     * that were cancelled — plus their cost, in the money column beside it.
     */
    public function test_a_cancelled_order_is_not_an_operators_work(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Адміністратор']);
        $operator = User::factory()->create(['name' => 'Олена Ковальчук']);

        Order::factory()->create([
            'user_id'    => $operator->id,
            'status'     => OrderStatus::CompletedIssued->value,
            'total_cost' => 100,
        ]);
        Order::factory()->create([
            'user_id'    => $operator->id,
            'status'     => OrderStatus::Cancelled->value,
            'total_cost' => 900,
        ]);

        $row = collect($this->analytics($admin)['operatorStats'])
            ->firstWhere('name', 'Олена Ковальчук');

        $this->assertEquals(1, $row['order_count'], 'Cancelled work is not work.');
        $this->assertEquals(100, (float) $row['total_cost']);
    }

    /**
     * Every other figure on the page is bounded by the date of the order. The
     * ranking was bounded by the date of the line item, and an item added to
     * an order the next day fell into a different period than its own order.
     */
    public function test_the_ranking_follows_the_order_date_not_the_line_item_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $order = Order::factory()->create([
            'user_id'    => $admin->id,
            'status'     => OrderStatus::CompletedIssued->value,
            'created_at' => Carbon::parse('2026-07-15 12:00:00', 'Europe/Kyiv')->utc(),
        ]);
        OrderItem::factory()->create([
            'order_id'     => $order->id,
            'service_name' => 'Друк А4',
            'quantity'     => 10,
            // Added to the same order the next day — a correction, a forgotten
            // position. It belongs to the order's day, not to its own.
            'created_at'   => Carbon::parse('2026-07-16 09:00:00', 'Europe/Kyiv')->utc(),
        ]);

        $response = $this->actingAs($admin)->get(route('analytics', [
            'from' => '2026-07-15',
            'to'   => '2026-07-15',
        ]));
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertEquals(1, $props['summary']['total_orders'], 'Precondition: the order is in the range.');
        $this->assertCount(
            1,
            $props['topServices'],
            'The order counts for this day but its own line item did not.',
        );
        $this->assertEquals(10, $props['topServices'][0]['total_qty']);
    }

    /**
     * The filter fields are filled from these props. They were the UTC bounds
     * formatted directly, so asking for 1 July put "30 June" back into the
     * field — and a submit of the unchanged form then asked for June.
     */
    public function test_the_filter_echoes_back_the_kyiv_dates_that_were_asked_for(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('analytics', [
            'from' => '2026-07-01',
            'to'   => '2026-07-31',
        ]));
        $response->assertOk();

        $filters = $response->viewData('page')['props']['filters'];

        $this->assertSame('2026-07-01', $filters['from']);
        $this->assertSame('2026-07-31', $filters['to']);
    }

    // ─── Health endpoint ─────────────────────────────────────────────

    /**
     * /health reports PHP and Laravel versions, free disk, failed-job counts
     * and, when something is down, the raw exception text — which for PDO
     * carries the host and database name. It was open to every logged-in user.
     */
    public function test_health_is_not_readable_by_an_operator(): void
    {
        $executor = User::factory()->create(['role' => 'executor']);

        $this->actingAs($executor)->get('/health')->assertForbidden();
    }

    /**
     * The status code here reflects whether the dependencies are up — there is
     * no Redis in the test container, so it is a legitimate 503. What this
     * asserts is that an admin is let through and gets the report.
     */
    public function test_health_is_readable_by_an_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/health');

        $this->assertNotSame(403, $response->getStatusCode(), 'An admin must not be turned away.');
        $response->assertJsonStructure(['status', 'checks' => ['database'], 'timestamp']);
        $this->assertSame('ok', $response->json('checks.database'));
    }

    public function test_ping_stays_public_and_says_nothing_else(): void
    {
        $response = $this->get('/ping');

        $response->assertOk();
        $this->assertSame(['status' => 'ok'], $response->json());
    }
}
