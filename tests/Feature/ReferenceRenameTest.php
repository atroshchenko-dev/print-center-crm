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
use App\Models\SignatoryGroup;
use App\Models\UniversityRef;
use App\Models\User;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * What a rename in the reference book does to the orders that point at it.
 *
 * `orders.authorized_person` and `orders.cost_center` are **strings**, not keys.
 * Every rule that spends a quota looks the reference up by that string:
 * `LimitService::incrementUsage()` finds the `Department` by name,
 * `groupUsageThisMonth()` matches `whereIn(authorized_person, …)` against the
 * names of the group's signatories. So an admin correcting a surname or a
 * department's title — an ordinary Tuesday on the university reference page —
 * silently cuts every order taken before that moment loose from its limit.
 *
 * Round 18's brief has the signatory half of this written down as a trace to
 * reproduce first (§2.1). The cost-centre half turned up next to it, and is the
 * more expensive of the two: the group pool merely forgets what was spent,
 * while the department counter never records the spend at all.
 */
class ReferenceRenameTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $executor;

    private Shift $shift;

    private Service $service;

    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();

        // A fixed 31-day Kyiv month, so the pool arithmetic is stated, not guessed.
        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00', 'Europe/Kyiv'));

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->executor = User::factory()->create(['role' => 'executor']);

        $equipment = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);
        $this->shift = app(ShiftService::class)->openShift($this->executor, [
            ['equipment_id' => $equipment->id, 'counter_value' => 10_000],
        ]);

        $this->category = ServiceCategory::factory()->create([
            'available_for' => ['internal', 'commercial'],
        ]);

        // One click per unit, so "quantity" reads as "copies" throughout.
        $this->service = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'type' => 'static',
            'base_price_cost' => 1.00,
            'base_price_commercial' => 2.00,
            'counter_type' => 'bw',
            'clicks_per_unit' => 1,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function place(int $copies, string $person, ?string $costCentre = null)
    {
        return $this->actingAs($this->executor)->post(route('orders.store'), [
            'type' => 'internal',
            'authorized_person' => $person,
            'cost_center' => $costCentre,
            'items' => [
                ['service_id' => $this->service->id, 'quantity' => $copies],
            ],
        ]);
    }

    // ─── The cost centre ─────────────────────────────────

    /**
     * The department's monthly counter moves when an order is **issued**, and
     * `incrementUsage()` finds the department by the string on the order. Rename
     * the department between taking the order and handing it over and the lookup
     * misses — `incrementUsage()` returns without a word, and the quota it was
     * defending is spent by a job it never saw.
     */
    public function test_renaming_a_cost_centre_keeps_the_orders_taken_under_it(): void
    {
        $department = Department::create(['name' => 'Кафедра економіки', 'type' => 'department', 'is_active' => true]);
        $limit = DepartmentLimit::create([
            'department_id' => $department->id,
            'limit_type' => 'bw_copies',
            'monthly_limit' => 1_000,
            'current_usage' => 0,
        ]);

        $this->place(100, 'Іваненко Іван Іванович', 'Кафедра економіки');
        $order = Order::latest('id')->first();

        $this->actingAs($this->admin)
            ->patch(route('admin.university.departments.update', $department), [
                'name' => 'Кафедра економіки та фінансів',
                'type' => 'department',
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertSame(
            'Кафедра економіки та фінансів',
            $order->fresh()->cost_center,
            'the order names the cost centre it belongs to, which has just been renamed',
        );

        // Hand the order over — this is what spends the quota.
        $this->actingAs($this->executor)->patch(route('orders.status', $order), [
            'status' => OrderStatus::CompletedIssued->value,
            'version' => $order->fresh()->version,
        ]);

        $this->assertSame(100, $limit->fresh()->current_usage);
    }

    /**
     * The counter is the department's, so it does not matter which of its names
     * the order was taken under: giving the quota back has to find the same row
     * the issue found.
     *
     * An issued order is reversed by **deleting** it, not by cancelling it —
     * `cancel()` refuses every terminal status. See
     * `test_an_issued_order_is_not_cancellable_it_is_deleted()`.
     */
    public function test_the_returned_quota_finds_the_renamed_cost_centre_too(): void
    {
        $department = Department::create(['name' => 'Кафедра права', 'type' => 'department', 'is_active' => true]);
        $limit = DepartmentLimit::create([
            'department_id' => $department->id,
            'limit_type' => 'bw_copies',
            'monthly_limit' => 1_000,
            'current_usage' => 0,
        ]);

        $this->place(100, 'Іваненко Іван Іванович', 'Кафедра права');
        $order = Order::latest('id')->first();

        $this->actingAs($this->executor)->patch(route('orders.status', $order), [
            'status' => OrderStatus::CompletedIssued->value,
            'version' => $order->fresh()->version,
        ]);

        $this->assertSame(100, $limit->fresh()->current_usage);

        $this->actingAs($this->admin)
            ->patch(route('admin.university.departments.update', $department), [
                'name' => 'Юридичний факультет',
                'type' => 'department',
                'is_active' => true,
            ]);

        $this->actingAs($this->admin)->delete(route('orders.destroy', $order), [
            'reason' => 'Технічний брак, передрук',
        ]);

        $this->assertSame(0, $limit->fresh()->current_usage, 'the quota comes back to the row it left');
    }

    /**
     * Which path reverses an issued order, stated as a test.
     *
     * `cancel()` refuses every terminal status on its third line, and
     * `completed_issued` is one — so the three reversal blocks it carried
     * underneath that guard (the ledger, the stock and the quota, each citing
     * ТЗ) could never run. They are a second copy of what `destroy()` does, and
     * a second copy is how two rules drift apart. Round 18
     * removed them; this test is what keeps the remaining path named.
     */
    public function test_an_issued_order_is_not_cancellable_it_is_deleted(): void
    {
        $department = Department::create(['name' => 'Кафедра історії', 'type' => 'department', 'is_active' => true]);
        $limit = DepartmentLimit::create([
            'department_id' => $department->id,
            'limit_type' => 'bw_copies',
            'monthly_limit' => 1_000,
            'current_usage' => 0,
        ]);

        $this->place(100, 'Іваненко Іван Іванович', 'Кафедра історії');
        $order = Order::latest('id')->first();

        $this->actingAs($this->executor)->patch(route('orders.status', $order), [
            'status' => OrderStatus::CompletedIssued->value,
            'version' => $order->fresh()->version,
        ]);

        $this->actingAs($this->admin)->post(route('orders.cancel', $order), [
            'reason' => 'Технічний брак',
            'is_technical_defect' => true,
            'version' => $order->fresh()->version,
        ]);

        $this->assertSame(OrderStatus::CompletedIssued, $order->fresh()->status);
        $this->assertSame(100, $limit->fresh()->current_usage, 'nothing was reversed, because nothing was cancelled');
    }

    // ─── The signatory ───────────────────────────────────

    /**
     * §2.1 of the round-18 brief, reproduced: the group pool is summed by
     * matching `orders.authorized_person` against the names of the group's
     * signatories. Correct a surname and everything that person printed this
     * month stops counting — the pool refills itself, and the next order goes
     * through under a quota that was already spent.
     */
    public function test_renaming_a_signatory_keeps_what_they_have_already_printed(): void
    {
        $group = SignatoryGroup::factory()->create(['name' => 'Деканат', 'daily_limit' => 10]);
        $signatory = UniversityRef::factory()->create([
            'full_name' => 'Іваненко Іван Іванович',
            'signatory_group_id' => $group->id,
            'is_active' => true,
        ]);

        // August has 31 days: the pool is 310.
        $this->assertSame(310, $group->monthlyLimit());

        $this->place(300, 'Іваненко Іван Іванович');

        $this->actingAs($this->admin)
            ->patch(route('admin.university.signatories.update', $signatory), [
                'full_name' => 'Іваненко-Ковальчук Іван Іванович',
                'signatory_group_id' => $group->id,
                'is_active' => true,
            ])
            ->assertRedirect();

        // The 300 copies were spent by this person under their former name.
        $this->place(20, 'Іваненко-Ковальчук Іван Іванович');

        $order = Order::latest('id')->first();

        $this->assertTrue($order->limit_exceeded, '320 copies against a pool of 310');
        $this->assertStringContainsString('320', session('warning') ?? '');
    }

    /**
     * The rename is a correction, not a transfer: the orders keep pointing at
     * the same person, which is what makes the sum above come out right.
     */
    public function test_the_orders_carry_the_corrected_name(): void
    {
        $signatory = UniversityRef::factory()->create([
            'full_name' => 'Марунова О.О.',
            'is_active' => true,
        ]);

        $this->place(5, 'Марунова О.О.');
        $order = Order::latest('id')->first();

        $this->actingAs($this->admin)
            ->patch(route('admin.university.signatories.update', $signatory), [
                'full_name' => 'Марунова Ольга Олександрівна',
                'is_active' => true,
            ]);

        $this->assertSame('Марунова Ольга Олександрівна', $order->fresh()->authorized_person);
    }

    /**
     * An edit that does not touch the name touches no order — the reference page
     * saves the whole row on every change, including deactivations.
     */
    public function test_an_edit_that_leaves_the_name_alone_leaves_the_orders_alone(): void
    {
        $signatory = UniversityRef::factory()->create([
            'full_name' => 'Петренко Петро Петрович',
            'is_active' => true,
        ]);

        $this->place(5, 'Петренко Петро Петрович');
        $order = Order::latest('id')->first();
        $updatedAt = $order->updated_at;

        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00', 'Europe/Kyiv'));

        $this->actingAs($this->admin)
            ->patch(route('admin.university.signatories.update', $signatory), [
                'full_name' => 'Петренко Петро Петрович',
                'position' => 'Декан',
                'is_active' => false,
            ]);

        $order->refresh();

        $this->assertSame('Петренко Петро Петрович', $order->authorized_person);
        $this->assertEquals($updatedAt, $order->updated_at, 'nothing was rewritten');
    }
}
