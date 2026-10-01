<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Shift;
use App\Models\SignatoryGroup;
use App\Models\UniversityRef;
use App\Models\User;
use App\Services\LimitService;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The signatory group's copy quota, and the categories its members may order.
 *
 * Round 15 found both set on a form, printed in a table and read by nobody:
 * `signatory_groups.daily_limit` had no reader anywhere in the ordering path,
 * and the allowed-category rule of ТЗ §3 was enforced by `Orders/Create.vue`
 * alone, in a codebase whose own comment says limits are checked on the backend
 * and not trusted from the client.
 *
 * Owner's decisions, 2026-07-31: the daily figure is spent as a **monthly
 * pool** (daily × calendar days of the Kyiv month) which may be spent in a
 * single day, and both rules **warn and record** rather than refuse — the same
 * footing ТЗ §3 puts the department limit on.
 */
class SignatoryGroupLimitTest extends TestCase
{
    use RefreshDatabase;

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
        Carbon::setTestNow(Carbon::parse('2026-07-15 09:00:00', 'Europe/Kyiv'));

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
            'service_category_id'   => $this->category->id,
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

    private function group(?int $dailyLimit, array $categoryIds = []): SignatoryGroup
    {
        $group = SignatoryGroup::factory()->create([
            'name'        => 'Деканат',
            'daily_limit' => $dailyLimit,
        ]);

        $group->categories()->sync($categoryIds);

        return $group;
    }

    private function signatory(?SignatoryGroup $group, string $name = 'Іваненко Іван Іванович'): UniversityRef
    {
        return UniversityRef::factory()->create([
            'full_name'          => $name,
            'signatory_group_id' => $group?->id,
            'is_active'          => true,
        ]);
    }

    private function place(int $copies, string $person = 'Іваненко Іван Іванович', ?Service $service = null)
    {
        return $this->actingAs($this->executor)->post(route('orders.store'), [
            'type'              => 'internal',
            'authorized_person' => $person,
            'cost_center'       => 'Кафедра тестування',
            'items'             => [
                ['service_id' => ($service ?? $this->service)->id, 'quantity' => $copies],
            ],
        ]);
    }

    /**
     * The same order, edited. Everything the edit form sends, including the
     * `limit_exceeded` checkbox an operator ticks by hand.
     */
    private function editTo(Order $order, int $copies, ?Service $service = null, bool $clientSays = false)
    {
        return $this->actingAs($this->executor)->put(route('orders.update', $order), [
            'type'              => 'internal',
            'authorized_person' => $order->authorized_person,
            'cost_center'       => $order->cost_center,
            'version'           => $order->version,
            'limit_exceeded'    => $clientSays,
            'items'             => [
                ['service_id' => ($service ?? $this->service)->id, 'quantity' => $copies],
            ],
        ]);
    }

    /** Copies already spent this month, without going through the controller. */
    private function alreadyPrinted(int $copies, string $person = 'Іваненко Іван Іванович'): Order
    {
        $order = Order::factory()->internal()->create([
            'authorized_person' => $person,
            'is_backdated'      => false,
        ]);

        OrderItem::factory()->create([
            'order_id'   => $order->id,
            'service_id' => $this->service->id,
            'quantity'   => $copies,
            'bw_clicks'  => $copies,
        ]);

        return $order;
    }

    // ─── The pool ────────────────────────────────────────

    public function test_the_daily_figure_becomes_a_pool_for_the_calendar_month(): void
    {
        $group = $this->group(10);

        // July has 31 days.
        $this->assertSame(310, $group->monthlyLimit());

        Carbon::setTestNow(Carbon::parse('2026-02-10 09:00:00', 'Europe/Kyiv'));
        $this->assertSame(280, $group->monthlyLimit(), 'February 2026 has 28 days');
    }

    public function test_no_daily_limit_means_no_pool(): void
    {
        $this->assertNull($this->group(null)->monthlyLimit());
        $this->assertNull($this->group(0)->monthlyLimit(), 'Zero is what the form calls "без ліміту"');
    }

    /**
     * The whole point of the owner's decision: a month's worth handed in at once
     * goes through, because that is how this department receives work.
     */
    public function test_the_whole_pool_can_be_spent_in_one_order(): void
    {
        $this->signatory($this->group(10));

        $this->place(310)->assertRedirect();

        $this->assertFalse(Order::latest('id')->first()->limit_exceeded);
        $this->assertNull(session('warning'));
        $this->assertDatabaseMissing('audit_logs', ['event_type' => 'limit_exceeded']);
    }

    public function test_one_copy_past_the_pool_warns_and_is_recorded_but_still_saves(): void
    {
        $this->signatory($this->group(10));

        $response = $this->place(311);

        $response->assertRedirect();
        $order = Order::latest('id')->first();

        $this->assertNotNull($order, 'The order is saved — ТЗ makes this a warning, not a refusal');
        $this->assertTrue($order->limit_exceeded);
        $this->assertStringContainsString('Деканат', session('warning'));
        $this->assertStringContainsString('311', session('warning'));
        $this->assertStringContainsString('310', session('warning'));

        $this->assertDatabaseHas('audit_logs', ['event_type' => 'limit_exceeded']);
    }

    /**
     * The quota belongs to the group, not to the person: what one signatory
     * spends is gone for the others.
     */
    public function test_the_pool_is_shared_by_everyone_in_the_group(): void
    {
        $group = $this->group(10);
        $this->signatory($group, 'Іваненко Іван Іванович');
        $this->signatory($group, 'Петренко Петро Петрович');

        $this->alreadyPrinted(300, 'Петренко Петро Петрович');

        $this->place(11, 'Іваненко Іван Іванович')->assertRedirect();

        $this->assertTrue(Order::latest('id')->first()->limit_exceeded);
    }

    public function test_a_signatory_outside_any_group_has_no_quota(): void
    {
        $this->signatory(null);

        $this->place(10_000)->assertRedirect();

        $this->assertFalse(Order::latest('id')->first()->limit_exceeded);
    }

    /**
     * Cancelled work was not printed for the department, and a retro entry
     * records a month whose quota has already been spent and reset. Neither is
     * in every other limit path either.
     */
    public function test_cancelled_and_retro_orders_do_not_eat_the_pool(): void
    {
        $this->signatory($this->group(10));

        $this->alreadyPrinted(300)->update(['status' => 'cancelled']);
        $this->alreadyPrinted(300)->update(['is_backdated' => true]);

        $this->place(310)->assertRedirect();

        $this->assertFalse(Order::latest('id')->first()->limit_exceeded);
    }

    public function test_last_months_orders_do_not_eat_this_months_pool(): void
    {
        $this->signatory($this->group(10));

        $june = $this->alreadyPrinted(300);
        Order::where('id', $june->id)->update(['created_at' => Carbon::parse('2026-06-20 09:00:00', 'Europe/Kyiv')->utc()]);

        $this->place(310)->assertRedirect();

        $this->assertFalse(Order::latest('id')->first()->limit_exceeded);
    }

    /**
     * «Копій», not «ч/б копій» — the field says copies, and a colour page is a
     * copy. This is the one place the group quota differs from the cost-centre
     * one, whose limit_type is literally `bw_copies`.
     */
    public function test_colour_copies_count_against_the_pool_too(): void
    {
        $this->signatory($this->group(1));   // 31 copies for July

        $order = Order::factory()->internal()->create([
            'authorized_person' => 'Іваненко Іван Іванович',
            'is_backdated'      => false,
        ]);
        OrderItem::factory()->create([
            'order_id'     => $order->id,
            'service_id'   => $this->service->id,
            'quantity'     => 31,
            'bw_clicks'    => 0,
            'color_clicks' => 31,
        ]);

        $this->place(1)->assertRedirect();

        $this->assertTrue(Order::latest('id')->first()->limit_exceeded);
    }

    // ─── Allowed categories ──────────────────────────────

    public function test_a_service_outside_the_groups_categories_warns_and_is_recorded(): void
    {
        $allowed = ServiceCategory::factory()->create(['available_for' => ['internal']]);
        $this->signatory($this->group(null, [$allowed->id]));

        $this->place(5)->assertRedirect();

        $this->assertNotNull(Order::latest('id')->first(), 'Saved, not refused');
        $this->assertStringContainsString($this->service->name, session('warning'));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'category_not_allowed']);
    }

    public function test_a_service_inside_the_groups_categories_passes_quietly(): void
    {
        $this->signatory($this->group(null, [$this->category->id]));

        $this->place(5)->assertRedirect();

        $this->assertNull(session('warning'));
        $this->assertDatabaseMissing('audit_logs', ['event_type' => 'category_not_allowed']);
    }

    /**
     * An empty category list is what the admin form means by "no restriction",
     * and the page reads it the same way.
     */
    public function test_a_group_with_no_categories_restricts_nothing(): void
    {
        $this->signatory($this->group(null, []));

        $this->place(5)->assertRedirect();

        $this->assertNull(session('warning'));
        $this->assertDatabaseMissing('audit_logs', ['event_type' => 'category_not_allowed']);
    }

    /**
     * The page drops the «Інше» tab entirely once a signatory narrows the list,
     * so a service with no category is outside. The server mirrors the client
     * rather than inventing its own rule.
     */
    public function test_a_service_with_no_category_is_outside_a_narrowed_list(): void
    {
        $this->signatory($this->group(null, [$this->category->id]));

        $uncategorised = Service::factory()->create([
            'service_category_id'   => null,
            'type'                  => 'static',
            'base_price_cost'       => 1.00,
            'base_price_commercial' => 2.00,
            'counter_type'          => 'bw',
            'clicks_per_unit'       => 1,
            'is_active'             => true,
        ]);

        $this->place(5, service: $uncategorised)->assertRedirect();

        $this->assertStringContainsString($uncategorised->name, session('warning'));
    }

    /**
     * A retired service still sat outside the signatory's categories when the
     * order was taken. Dropping it from the lookup is how a mixed order quietly
     * loses half its warning — the same `withTrashed()` argument
     * `OrderItem::scopeDuePersonalDataRemoval` already makes for this lookup.
     */
    public function test_a_service_retired_since_the_order_is_still_named(): void
    {
        $allowed = ServiceCategory::factory()->create(['available_for' => ['internal']]);
        $this->signatory($this->group(null, [$allowed->id]));

        $this->service->delete();

        $outside = app(LimitService::class)->servicesOutsideSignatoryCategories(
            'Іваненко Іван Іванович',
            collect([$this->service->id]),
        );

        $this->assertCount(1, $outside);
        $this->assertStringContainsString($this->service->name, $outside[0]);
    }

    /**
     * An item whose service reference is gone cannot be checked against any
     * category — which is a thing to say, not a thing to omit.
     */
    public function test_items_with_no_service_are_reported_not_dropped(): void
    {
        $allowed = ServiceCategory::factory()->create(['available_for' => ['internal']]);
        $this->signatory($this->group(null, [$allowed->id]));

        $outside = app(LimitService::class)->servicesOutsideSignatoryCategories(
            'Іваненко Іван Іванович',
            collect([null, null]),
        );

        $this->assertCount(1, $outside);
        $this->assertStringContainsString('2', $outside[0]);
    }

    /**
     * With one category per order the operator knew which category was meant.
     * With several, a list of service names alone makes them reverse-map it.
     */
    public function test_the_warning_names_the_category_the_service_sits_in(): void
    {
        $allowed = ServiceCategory::factory()->create(['available_for' => ['internal']]);
        $this->signatory($this->group(null, [$allowed->id]));

        $this->place(5)->assertRedirect();

        $this->assertStringContainsString($this->service->name, session('warning'));
        $this->assertStringContainsString($this->category->name, session('warning'));
    }

    /**
     * The case the whole feature creates: one order, two categories, one of them
     * outside the signatory's list. The allowed half must stay unmentioned, or
     * the warning stops being read.
     */
    public function test_a_mixed_order_names_only_the_forbidden_half(): void
    {
        $allowedCategory = ServiceCategory::factory()->create([
            'name'          => 'Дозволена категорія',
            'available_for' => ['internal'],
        ]);
        $inside = Service::factory()->create([
            'name'                  => 'Дозволена послуга',
            'service_category_id'   => $allowedCategory->id,
            'type'                  => 'static',
            'base_price_cost'       => 1.00,
            'base_price_commercial' => 2.00,
            'counter_type'          => 'bw',
            'clicks_per_unit'       => 1,
            'is_active'             => true,
        ]);
        $this->signatory($this->group(null, [$allowedCategory->id]));

        $this->actingAs($this->executor)->post(route('orders.store'), [
            'type'              => 'internal',
            'authorized_person' => 'Іваненко Іван Іванович',
            'cost_center'       => 'Кафедра тестування',
            'items'             => [
                ['service_id' => $inside->id, 'quantity' => 5],
                ['service_id' => $this->service->id, 'quantity' => 3],
            ],
        ])->assertRedirect();

        $this->assertStringContainsString($this->service->name, session('warning'));
        $this->assertStringNotContainsString('Дозволена послуга', session('warning'));
    }

    /**
     * The group pool is one pool for every category, and a mixed order spends it
     * from every line. Nothing in the pool code is category-aware — this pins
     * that, so nobody "optimises" it into a per-category lookup later.
     *
     * Both services count `bw` clicks on purpose: the point being pinned is that
     * two *categories* land in one pool, and a colour service would make the
     * assertion depend on how the controller maps `counter_type` to click
     * columns — a different question, already pinned by
     * `test_colour_copies_count_against_the_pool_too`.
     */
    public function test_the_pool_counts_every_category_of_one_order(): void
    {
        $secondCategory = ServiceCategory::factory()->create(['available_for' => ['internal']]);
        $second = Service::factory()->create([
            'service_category_id'   => $secondCategory->id,
            'type'                  => 'static',
            'base_price_cost'       => 2.00,
            'base_price_commercial' => 4.00,
            'counter_type'          => 'bw',
            'clicks_per_unit'       => 1,
            'is_active'             => true,
        ]);
        $this->signatory($this->group(10));   // 310 copies for July

        $this->actingAs($this->executor)->post(route('orders.store'), [
            'type'              => 'internal',
            'authorized_person' => 'Іваненко Іван Іванович',
            'cost_center'       => 'Кафедра тестування',
            'items'             => [
                ['service_id' => $this->service->id, 'quantity' => 200],
                ['service_id' => $second->id, 'quantity' => 200],
            ],
        ])->assertRedirect();

        $this->assertTrue(
            Order::latest('id')->first()->limit_exceeded,
            '400 copies across two categories is one order against one pool',
        );
    }

    /**
     * Deactivating a group is not a decision to grant unlimited printing, and
     * since round 15 the admin can see such rows on the page.
     */
    public function test_a_deactivated_group_still_holds_its_quota(): void
    {
        $group = $this->group(10);
        $this->signatory($group);
        $group->delete();

        $this->place(311)->assertRedirect();

        $this->assertTrue(Order::latest('id')->first()->limit_exceeded);
    }

    /**
     * The quota half of a deactivated group is pinned above; the category
     * half is a separate query in `LimitService` and needs its own case —
     * `checkSignatoryLimit()` and `servicesOutsideSignatoryCategories()`
     * agreeing about `withTrashed()` on the group is not something either one
     * proves for the other.
     */
    public function test_a_deactivated_group_still_restricts_its_categories(): void
    {
        $allowed = ServiceCategory::factory()->create(['available_for' => ['internal']]);
        $group = $this->group(null, [$allowed->id]);
        $this->signatory($group);
        $group->delete();

        $this->place(5)->assertRedirect();

        $this->assertNotNull(Order::latest('id')->first(), 'Saved, not refused');
        $this->assertStringContainsString($this->service->name, session('warning'));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'category_not_allowed']);
    }

    // ─── The edit path ───────────────────────────────────
    //
    // Every rule above was asked on `store()` and on nothing else. An order
    // placed inside the quota and then edited tenfold left no trace at all: no
    // warning to the operator, no line in the audit journal, and a
    // `limit_exceeded` column holding whatever the browser last said. This is
    // not a regression of round 15 — the cost-centre quota was never checked on
    // an edit either; round 15 only made the hole worth looking at by giving the
    // group quota teeth.

    public function test_editing_an_order_past_the_pool_warns_and_is_recorded(): void
    {
        $this->signatory($this->group(10));

        $this->place(10)->assertRedirect();
        $order = Order::latest('id')->first();
        $this->assertFalse($order->limit_exceeded, 'Inside the pool when it was placed');

        $response = $this->editTo($order, 311);

        $response->assertRedirect();
        $order->refresh();

        $this->assertTrue($order->limit_exceeded);
        $this->assertStringContainsString('Деканат', session('warning'));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'limit_exceeded']);
    }

    /**
     * The order is still saved — an edit is on the same footing as a creation,
     * and ТЗ §3 makes the limit a warning that records rather than a refusal.
     */
    public function test_an_over_limit_edit_still_saves(): void
    {
        $this->signatory($this->group(10));

        $this->place(10)->assertRedirect();
        $order = Order::latest('id')->first();

        $this->editTo($order, 311)->assertRedirect();

        $this->assertEqualsWithDelta(311.0, (float) $order->fresh()->items->sum('quantity'), 0.001);
    }

    /**
     * The flag is what the server counted, not what the form posted. Both
     * directions: a browser claiming everything is fine when it is not, and an
     * operator's hand-ticked checkbox on an order that is inside its quota.
     */
    public function test_the_flag_is_computed_on_an_edit_and_not_taken_from_the_form(): void
    {
        $this->signatory($this->group(10));

        $this->place(10)->assertRedirect();
        $order = Order::latest('id')->first();

        $this->editTo($order, 311, clientSays: false);
        $this->assertTrue($order->fresh()->limit_exceeded, 'Over the pool, whatever the form said');

        $order = $order->fresh();
        $this->editTo($order, 10, clientSays: true);
        $this->assertFalse($order->fresh()->limit_exceeded, 'Back inside the pool, whatever the form said');
    }

    /**
     * An order could be placed on an allowed service and edited onto a forbidden
     * one: `Orders/Edit.vue` filters the tabs exactly as `Create.vue` does, and
     * that filter is precisely what round 15 declared insufficient.
     */
    public function test_editing_onto_a_service_outside_the_categories_is_recorded(): void
    {
        $allowed = ServiceCategory::factory()->create(['available_for' => ['internal']]);
        $inside = Service::factory()->create([
            'service_category_id'   => $allowed->id,
            'type'                  => 'static',
            'base_price_cost'       => 1.00,
            'base_price_commercial' => 2.00,
            'counter_type'          => 'bw',
            'clicks_per_unit'       => 1,
            'is_active'             => true,
        ]);
        $this->signatory($this->group(null, [$allowed->id]));

        $this->place(5, service: $inside)->assertRedirect();
        $this->assertDatabaseMissing('audit_logs', ['event_type' => 'category_not_allowed']);

        $order = Order::latest('id')->first();
        $this->editTo($order, 5, service: $this->service)->assertRedirect();

        $this->assertStringContainsString($this->service->name, session('warning'));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'category_not_allowed']);
    }

    /**
     * An edit that stays inside every rule says nothing — otherwise the warning
     * becomes noise and stops being read.
     */
    public function test_an_edit_inside_the_rules_passes_quietly(): void
    {
        $this->signatory($this->group(10, [$this->category->id]));

        $this->place(10)->assertRedirect();
        $order = Order::latest('id')->first();

        $this->editTo($order, 20)->assertRedirect();

        $this->assertNull(session('warning'));
        $this->assertFalse($order->fresh()->limit_exceeded);
        $this->assertDatabaseMissing('audit_logs', ['event_type' => 'limit_exceeded']);
    }
}
