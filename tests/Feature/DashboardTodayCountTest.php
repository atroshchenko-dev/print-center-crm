<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Order;
use App\Models\User;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Today" on the dashboard means today in Kyiv.
 *
 * Timestamps are stored in UTC and the app timezone is UTC, so DATE(created_at)
 * yields a UTC date. It was being compared against today('Europe/Kyiv'). The
 * two disagree between midnight and 03:00 Kyiv — which is inside the working
 * day, because the shift runs until the auto-close at 03:00 — and orders taken
 * in that window counted as zero.
 */
class DashboardTodayCountTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_order_taken_after_midnight_kyiv_counts_towards_today(): void
    {
        // 01:00 in Kyiv on the 28th is 22:00 UTC on the 27th: same working
        // day for the operator, different date for the database.
        Carbon::setTestNow(Carbon::parse('2026-07-28 01:00:00', 'Europe/Kyiv'));

        $admin     = User::factory()->create(['role' => 'admin']);
        $equipment = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);

        app(ShiftService::class)->openShift($admin, [
            ['equipment_id' => $equipment->id, 'counter_value' => 10000],
        ]);

        $this->assertSame(
            '2026-07-27',
            now()->utc()->toDateString(),
            'Precondition: the UTC date is behind the Kyiv date right now.',
        );

        Order::factory()->internal()->create([
            'user_id'    => $admin->id,
            'status'     => 'new',
            'created_at' => now(),
        ]);

        Order::factory()->commercial()->create([
            'user_id'    => $admin->id,
            'status'     => 'new',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();

        $charts = $response->viewData('page')['props']['chartData'];

        $this->assertSame(1, $charts['todayInt'], 'The internal order was taken today in Kyiv.');
        $this->assertSame(1, $charts['todayCom'], 'The commercial order was taken today in Kyiv.');
    }

    /**
     * The ordinary case still has to work — this is raw SQL with positional
     * parameters, so a miscount here shifts every figure on the page.
     */
    public function test_midday_orders_count_normally(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 13:00:00', 'Europe/Kyiv'));

        $admin     = User::factory()->create(['role' => 'admin']);
        $equipment = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);

        app(ShiftService::class)->openShift($admin, [
            ['equipment_id' => $equipment->id, 'counter_value' => 10000],
        ]);

        Order::factory()->internal()->create([
            'user_id'    => $admin->id,
            'status'     => 'new',
            'created_at' => now(),
        ]);

        // Three days back: today's tile must not claim it.
        Order::factory()->internal()->create([
            'user_id'    => $admin->id,
            'status'     => 'new',
            'created_at' => now()->subDays(3),
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();

        $charts = $response->viewData('page')['props']['chartData'];

        $this->assertSame(1, $charts['todayInt']);
        $this->assertSame(0, $charts['todayCom']);
        $this->assertSame(2, $charts['monthInt'], 'Both orders fall inside the month.');
    }

    /**
     * The "today" tiles were moved onto the Kyiv day; the week and month tiles
     * beside them were left on `now()`, which is UTC. A month therefore began
     * at 03:00 Kyiv on the 1st, and the hours before that still belonged to the
     * month before — on a screen whose other tiles had already turned over.
     */
    public function test_the_month_tile_turns_over_at_kyiv_midnight_on_the_first(): void
    {
        // 01:00 Kyiv on 1 July is 22:00 UTC on 30 June.
        Carbon::setTestNow(Carbon::parse('2026-07-01 01:00:00', 'Europe/Kyiv'));

        $admin     = User::factory()->create(['role' => 'admin']);
        $equipment = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);

        app(ShiftService::class)->openShift($admin, [
            ['equipment_id' => $equipment->id, 'counter_value' => 10000],
        ]);

        Order::factory()->internal()->create([
            'user_id'    => $admin->id,
            'status'     => 'new',
            'created_at' => now(),
        ]);

        Order::factory()->internal()->create([
            'user_id'    => $admin->id,
            'status'     => 'new',
            'created_at' => Carbon::parse('2026-06-15 12:00:00', 'Europe/Kyiv'),
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();

        $charts = $response->viewData('page')['props']['chartData'];

        $this->assertSame(1, $charts['monthInt'], 'Only the order taken in July belongs to July.');
        $this->assertSame(1, $charts['lastMonthInt'], 'The June order belongs to June.');
    }
}
