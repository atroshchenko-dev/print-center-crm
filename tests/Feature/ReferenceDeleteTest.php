<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Department;
use App\Models\DepartmentLimit;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The other half of the boundary round 18 opened: what a **delete** in the
 * reference book does to the orders that point at it.
 *
 * Round 18 carried a rename over to the orders (`CascadesRenameToOrders`), and
 * the argument it gave was that `orders.cost_center` is a string, so every rule
 * spending a quota looks the department up by that string and quietly gives up
 * when it misses. A delete misses in exactly the same way, and nothing carries
 * anything anywhere: the row is soft-deleted, the orders keep naming it, and
 * `Department::where('name', …)` no longer sees it because of the global scope.
 *
 * `LimitService` already answers this question for the *other* quota, in the
 * same file: `checkSignatoryLimit()` resolves the signatory and the group with
 * `withTrashed()`, and says why — a signatory still points at the group, and
 * deactivating one "is not a decision to grant unlimited printing". The
 * cost-centre half of the same file does not.
 */
class ReferenceDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $executor;

    private Shift $shift;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();

        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00', 'Europe/Kyiv'));

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->executor = User::factory()->create(['role' => 'executor']);

        $equipment = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);
        $this->shift = app(ShiftService::class)->openShift($this->executor, [
            ['equipment_id' => $equipment->id, 'counter_value' => 10_000],
        ]);

        $category = ServiceCategory::factory()->create([
            'available_for' => ['internal', 'commercial'],
        ]);

        // One click per unit, so "quantity" reads as "copies" throughout.
        $this->service = Service::factory()->create([
            'service_category_id'   => $category->id,
            'type'                  => 'static',
            'base_price_cost'       => 1.00,
            'base_price_commercial' => 2.00,
            'counter_type'          => 'bw',
            'clicks_per_unit'       => 1,
            'is_active'             => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function place(int $copies, string $costCentre)
    {
        return $this->actingAs($this->executor)->post(route('orders.store'), [
            'type'              => 'internal',
            'authorized_person' => 'Іваненко Іван Іванович',
            'cost_center'       => $costCentre,
            'items'             => [
                ['service_id' => $this->service->id, 'quantity' => $copies],
            ],
        ]);
    }

    private function departmentWithLimit(string $name, int $monthly = 1_000): DepartmentLimit
    {
        $department = Department::create(['name' => $name, 'type' => 'department', 'is_active' => true]);

        return DepartmentLimit::create([
            'department_id' => $department->id,
            'limit_type'    => 'bw_copies',
            'monthly_limit' => $monthly,
            'current_usage' => 0,
        ]);
    }

    /**
     * The order was taken while the department existed. Deleting the department
     * afterwards does not un-print the copies — they are handed over, and the
     * counter has to record the spend.
     *
     * This is the expensive half of R18-2 arriving by another road: a missed
     * increment is a quota spent with nothing recorded.
     */
    public function test_a_deleted_cost_centre_still_records_what_was_handed_over(): void
    {
        $limit = $this->departmentWithLimit('Кафедра економіки');

        $this->place(100, 'Кафедра економіки');
        $order = Order::latest('id')->first();

        $this->actingAs($this->admin)
            ->delete(route('admin.university.departments.destroy', $limit->department_id))
            ->assertRedirect();

        $this->actingAs($this->executor)->patch(route('orders.status', $order), [
            'status'  => OrderStatus::CompletedIssued->value,
            'version' => $order->fresh()->version,
        ]);

        $this->assertSame(100, $limit->fresh()->current_usage);
    }

    /**
     * And the same row gives the quota back, for the same reason the rename test
     * gives: the counter is the department's, not the order's.
     */
    public function test_a_deleted_cost_centre_takes_its_quota_back_too(): void
    {
        $limit = $this->departmentWithLimit('Кафедра права');

        $this->place(100, 'Кафедра права');
        $order = Order::latest('id')->first();

        $this->actingAs($this->executor)->patch(route('orders.status', $order), [
            'status'  => OrderStatus::CompletedIssued->value,
            'version' => $order->fresh()->version,
        ]);

        $this->assertSame(100, $limit->fresh()->current_usage);

        $this->actingAs($this->admin)
            ->delete(route('admin.university.departments.destroy', $limit->department_id));

        $this->actingAs($this->admin)->delete(route('orders.destroy', $order), [
            'reason' => 'Технічний брак, передрук',
        ]);

        $this->assertSame(0, $limit->fresh()->current_usage, 'the quota comes back to the row it left');
    }

    /**
     * A breached limit is a warning that still saves and still records (ТЗ §3).
     * Deleting the department is an edit of the reference book, not a decision
     * to grant the cost centre unlimited printing — the same sentence
     * `checkSignatoryLimit()` already has written above it about groups.
     */
    public function test_a_deleted_cost_centre_still_warns_when_its_limit_is_breached(): void
    {
        $limit = $this->departmentWithLimit('Кафедра історії', 50);

        $this->actingAs($this->admin)
            ->delete(route('admin.university.departments.destroy', $limit->department_id));

        $this->place(500, 'Кафедра історії');
        $order = Order::latest('id')->first();

        $this->assertTrue($order->limit_exceeded, '500 copies against a limit of 50');
    }

    /**
     * The auto-save that runs while an order is being written uses
     * `firstOrCreate(['name' => …])`, and the global scope hides the deleted row
     * from it — so it creates a **second** row under the same name. Neither
     * `departments.name` nor `university_refs.full_name` is unique, so nothing
     * downstream can tell the two apart, and `where('name', …)->first()` picks
     * whichever the database hands back.
     *
     * That is how the reference book comes to hold two rows reading the same
     * thing — the state the round-18 report recorded as a limitation of its own
     * fix, and the state production is already in with two «Марунова О.О.».
     */
    public function test_an_order_on_a_deleted_cost_centre_does_not_fork_the_reference_row(): void
    {
        $limit = $this->departmentWithLimit('Кафедра філософії');

        $this->actingAs($this->admin)
            ->delete(route('admin.university.departments.destroy', $limit->department_id));

        $this->place(10, 'Кафедра філософії');

        $this->assertSame(
            1,
            Department::withTrashed()->where('name', 'Кафедра філософії')->count(),
            'one cost centre, one row',
        );
    }
}
