<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\RisoPriceTier;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Shift;
use App\Models\SignatoryGroup;
use App\Models\UniversityRef;
use App\Models\User;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * OrderControllerTest — the HTTP surface of the busiest controller in the app.
 *
 * OrderController sat at 21% of 381 instructions, the largest blind spot left
 * after the 2026-07-27 audit. What existed covered the status machine and
 * cancellation; store, index filtering, edit, update, destroy, the batch
 * endpoint, the request-received toggle and approval sending had nothing.
 *
 * These are the guarantees that live in the controller rather than in a service,
 * so nothing below is a duplicate of OrderPricingIntegrityTest (which pins how
 * prices are built) or OrderWorkflowTest (which pins transitions).
 */
class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $executor;

    private Shift $shift;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();          // Telegram must not reach the network
        Notification::fake();  // nor must approval email

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->executor = User::factory()->create(['role' => 'executor']);

        $equipment = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);
        $this->shift = app(ShiftService::class)->openShift($this->executor, [
            ['equipment_id' => $equipment->id, 'counter_value' => 10_000],
        ]);

        // A plain static service, priced from the service row — enough to
        // exercise the controller without dragging a constructor in.
        // available_for is a JSON array, and ServiceAvailableForOrderType checks
        // membership — an internal-only category would refuse commercial orders.
        $category = ServiceCategory::factory()->create([
            'available_for' => ['internal', 'commercial'],
        ]);
        $this->service = Service::factory()->create([
            'service_category_id'   => $category->id,
            'type'                  => 'static',
            'base_price_cost'       => 2.50,
            'base_price_commercial' => 5.00,
            'is_active'             => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'type'              => 'internal',
            'authorized_person' => 'Тестовий Підписант',
            'cost_center'       => 'Кафедра тестування',
            'items'             => [
                ['service_id' => $this->service->id, 'quantity' => 4],
            ],
        ], $overrides);
    }

    // ─── store ──────────────────────────────────────────────────────

    public function test_an_internal_order_is_created_with_its_item_and_totals(): void
    {
        $response = $this->actingAs($this->executor)
            ->post(route('orders.store'), $this->orderPayload());

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $order = Order::latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame(OrderType::Internal, $order->type);
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame('Тестовий Підписант', $order->authorized_person);
        $this->assertSame('Кафедра тестування', $order->cost_center);
        $this->assertSame($this->shift->id, $order->shift_id);
        $this->assertSame($this->executor->id, $order->user_id);

        $this->assertCount(1, $order->items);
        $this->assertEqualsWithDelta(10.00, (float) $order->total_cost, 0.001, '4 × 2.50');
    }

    public function test_an_internal_order_needs_a_signatory(): void
    {
        $response = $this->actingAs($this->executor)
            ->post(route('orders.store'), $this->orderPayload(['authorized_person' => null]));

        $response->assertSessionHasErrors('authorized_person');
        $this->assertSame(0, Order::count());
    }

    public function test_a_commercial_order_needs_no_signatory(): void
    {
        $response = $this->actingAs($this->executor)->post(route('orders.store'), [
            'type'  => 'commercial',
            'items' => [['service_id' => $this->service->id, 'quantity' => 2]],
        ]);

        $response->assertRedirect();

        $order = Order::latest('id')->first();
        $this->assertSame(OrderType::Commercial, $order->type);
        $this->assertEqualsWithDelta(10.00, (float) $order->total_commercial, 0.001, '2 × 5.00');
    }

    public function test_an_order_with_no_items_is_refused(): void
    {
        $response = $this->actingAs($this->executor)
            ->post(route('orders.store'), $this->orderPayload(['items' => []]));

        $response->assertSessionHasErrors('items');
        $this->assertSame(0, Order::count());
    }

    public function test_internal_and_commercial_numbers_come_from_separate_series(): void
    {
        $this->actingAs($this->executor)->post(route('orders.store'), $this->orderPayload());
        $this->actingAs($this->executor)->post(route('orders.store'), [
            'type'  => 'commercial',
            'items' => [['service_id' => $this->service->id, 'quantity' => 1]],
        ]);

        $numbers = Order::orderBy('id')->pluck('order_number');

        $this->assertStringStartsWith('INT-', $numbers[0]);
        $this->assertStringStartsWith('COM-', $numbers[1]);
    }

    // ─── index ──────────────────────────────────────────────────────

    /**
     * Only `search`, `status` and `view` are applied server-side — and
     * `search` only in the month view since R3-1: the shift always arrives
     * complete, because the richer client-side search (items, initiator)
     * filters it on the page. The INT/COM chips in the UI filter the page
     * that was already sent, so there is no `type` parameter to test —
     * worth stating, since the two look alike.
     */
    public function test_the_list_can_be_narrowed_by_status(): void
    {
        $newOrder = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'status'  => OrderStatus::New,
        ]);
        $cancelled = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'status'  => OrderStatus::Cancelled,
        ]);

        $response = $this->actingAs($this->executor)
            ->get(route('orders.index', ['status' => OrderStatus::New->value]));

        $response->assertOk();
        $ids = $this->listedOrderIds($response);

        $this->assertTrue($ids->contains($newOrder->id));
        $this->assertFalse($ids->contains($cancelled->id), 'A status filter must exclude other statuses');
    }

    public function test_the_list_can_be_narrowed_by_search_term(): void
    {
        $wanted = Order::factory()->forShift($this->shift)->create([
            'user_id'     => $this->executor->id,
            'cost_center' => 'Кафедра фінансів',
        ]);
        $other = Order::factory()->forShift($this->shift)->create([
            'user_id'     => $this->executor->id,
            'cost_center' => 'Бібліотека',
        ]);

        $response = $this->actingAs($this->executor)
            ->get(route('orders.index', ['view' => 'month', 'search' => 'фінанс']));

        $response->assertOk();
        $ids = $this->listedOrderIds($response);

        $this->assertTrue($ids->contains($wanted->id), 'Search must match the cost centre, case-insensitively');
        $this->assertFalse($ids->contains($other->id));
    }

    /**
     * The search parameter is a month-view contract; the shift view always
     * arrives complete (finding R3-1, third audit pass 2026-08-09). Fix #91
     * carried the term across the view switch, and the server obligingly
     * filtered the shift by the narrower month fields — an order matching
     * only by its items vanished before the richer client-side search ever
     * saw it, and clearing the box reloaded nothing.
     */
    public function test_the_shift_view_arrives_complete_even_with_a_search_term(): void
    {
        $matching = Order::factory()->forShift($this->shift)->create([
            'user_id'     => $this->executor->id,
            'cost_center' => 'Кафедра фінансів',
        ]);
        $other = Order::factory()->forShift($this->shift)->create([
            'user_id'     => $this->executor->id,
            'cost_center' => 'Бібліотека',
        ]);

        $response = $this->actingAs($this->executor)
            ->get(route('orders.index', ['search' => 'фінанс']));

        $response->assertOk();
        $ids = $this->listedOrderIds($response);

        $this->assertTrue($ids->contains($matching->id));
        $this->assertTrue(
            $ids->contains($other->id),
            'The shift view must arrive complete; the client-side search filters it.',
        );
    }

    /**
     * The month search must answer the way the shift's client-side search
     * does (finding R3-4, third audit pass): the number matched with `like`
     * while every other field used `ilike`, so «int-2608» found the order in
     * the shift and lost it in the month; and the initiator — which CONTEXT
     * promises the Index searches — was not in the server's field list.
     */
    public function test_the_month_search_matches_order_numbers_case_insensitively(): void
    {
        $order = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
        ]);

        $response = $this->actingAs($this->executor)->get(route('orders.index', [
            'view'   => 'month',
            'search' => mb_strtolower($order->order_number),
        ]));

        $response->assertOk();
        $this->assertTrue(
            $this->listedOrderIds($response)->contains($order->id),
            'A lowercased order number must still find its order in the month.',
        );
    }

    public function test_the_month_search_covers_the_initiator(): void
    {
        $wanted = Order::factory()->forShift($this->shift)->create([
            'user_id'   => $this->executor->id,
            'initiator' => 'Петренко Марія',
        ]);
        $other = Order::factory()->forShift($this->shift)->create([
            'user_id'   => $this->executor->id,
            'initiator' => 'Іваненко Олег',
        ]);

        $response = $this->actingAs($this->executor)->get(route('orders.index', [
            'view'   => 'month',
            'search' => 'Петренко',
        ]));

        $response->assertOk();
        $ids = $this->listedOrderIds($response);

        $this->assertTrue($ids->contains($wanted->id));
        $this->assertFalse($ids->contains($other->id));
    }

    /**
     * The month is paginated, so its chips must filter on the server the way
     * its search does: a chip that only filters the loaded
     * page shows «Нічого не знайдено» while the matches sit on page two.
     */
    public function test_the_month_status_chips_filter_on_the_server(): void
    {
        $active = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'status'  => OrderStatus::New,
        ]);
        $finished = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'status'  => OrderStatus::CompletedIssued,
        ]);

        $ids = $this->listedOrderIds($this->actingAs($this->executor)
            ->get(route('orders.index', ['view' => 'month', 'status_group' => 'active'])));
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($finished->id), 'The active chip must exclude terminal orders server-side.');

        $ids = $this->listedOrderIds($this->actingAs($this->executor)
            ->get(route('orders.index', ['view' => 'month', 'status_group' => 'terminal'])));
        $this->assertTrue($ids->contains($finished->id));
        $this->assertFalse($ids->contains($active->id));
    }

    public function test_the_month_type_chip_filters_on_the_server(): void
    {
        $internal = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'type'    => OrderType::Internal,
        ]);
        $commercial = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'type'    => OrderType::Commercial,
        ]);

        $ids = $this->listedOrderIds($this->actingAs($this->executor)
            ->get(route('orders.index', ['view' => 'month', 'type' => 'commercial'])));

        $this->assertTrue($ids->contains($commercial->id));
        $this->assertFalse($ids->contains($internal->id));
    }

    /** `?search[]=x` must be a no-op, not a 500. */
    public function test_a_search_array_does_not_crash_the_index(): void
    {
        Order::factory()->forShift($this->shift)->create(['user_id' => $this->executor->id]);

        $this->actingAs($this->executor)
            ->get(route('orders.index', ['view' => 'month', 'search' => ['x']]))
            ->assertOk();
    }

    /**
     * «The shift arrives whole» is a contract every client-side feature on
     * the page leans on — search, chips, «Обрати всі» — and paginate(50) was
     * quietly shared by both views, so the 51st order of a busy shift would
     * fall off the working set with nothing saying so (finding R4-1, fourth
     * audit pass). The shift's ceiling is 500 now — a recorded decision like
     * carryover's limit(100), far beyond a physical day; if it is ever
     * crossed, the Pagination component appears rather than anything hiding.
     */
    public function test_the_shift_view_survives_its_fifty_first_order(): void
    {
        Order::factory()->count(55)->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
        ]);

        $response = $this->actingAs($this->executor)->get(route('orders.index'));

        $response->assertOk();
        $this->assertSame(
            55,
            $this->listedOrderIds($response)->count(),
            'The whole shift must be on the page, not its newest fifty.',
        );
    }

    /** `?repeat[]=x` used to hand find() an array — a Collection, a 500. */
    public function test_a_repeat_array_does_not_crash_the_create_form(): void
    {
        $this->actingAs($this->executor)
            ->get(route('orders.create', ['repeat' => ['x']]))
            ->assertOk();
    }

    /**
     * The create form needs the signatory→cost-centre map to narrow the
     * `CostCenterSelect` field (round: signatory-bound cost centres). The
     * map's own contents are `ReferenceDataService::signatoryCostCenters()`'s
     * job to get right — this only guards the wiring: that
     * `Inertia::render('Orders/Create', …)` still carries the prop at all.
     * Nothing else in this suite touches it, so a future edit to that
     * array could drop the line silently and every four-page rollout of
     * this feature would go dark with a green build and a passing
     * `assertOk()`.
     */
    public function test_the_create_form_carries_the_signatory_cost_centre_map(): void
    {
        $this->actingAs($this->executor)
            ->get(route('orders.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('signatory_cost_centers'));
    }

    /**
     * The other half of the R4-3 class (fifth pass): a non-numeric scalar
     * against a bigint column is a Postgres 22P02, so `?repeat=abc` passed
     * the is_scalar guard and crashed all the same.
     */
    public function test_a_non_numeric_repeat_is_ignored(): void
    {
        $this->actingAs($this->executor)
            ->get(route('orders.create', ['repeat' => 'abc']))
            ->assertOk();
    }

    /**
     * `/orders/abc` reached route model binding and died in the bigint cast
     * — and every numeric `{param}` in routes/web.php shared the hole (fifth
     * pass). With the global numeric patterns the route simply never
     * matches: a typo in the URL is a 404, not a 500 plus a Telegram alert.
     */
    public function test_a_non_numeric_order_id_is_not_found_rather_than_a_crash(): void
    {
        $this->actingAs($this->executor)
            ->get('/orders/abc')
            ->assertNotFound();
    }

    /**
     * %, _ and \ are LIKE syntax, not text (finding I-4, 2026-08-09 audit).
     * An operator searching "100%" was really searching "100" followed by
     * anything, so the list quietly widened instead of narrowing to the one
     * name with a percent sign in it. No injection — the pattern is bound —
     * just a match broader than the term the operator typed.
     */
    public function test_search_treats_like_wildcards_as_text(): void
    {
        $literal = Order::factory()->forShift($this->shift)->create([
            'user_id'     => $this->executor->id,
            'cost_center' => 'Знижка 100%',
        ]);
        $wider = Order::factory()->forShift($this->shift)->create([
            'user_id'     => $this->executor->id,
            'cost_center' => 'Знижка 100 грн',
        ]);

        $response = $this->actingAs($this->executor)
            ->get(route('orders.index', ['view' => 'month', 'search' => '100%']));

        $response->assertOk();
        $ids = $this->listedOrderIds($response);

        $this->assertTrue($ids->contains($literal->id), 'The percent sign is part of the term.');
        $this->assertFalse($ids->contains($wider->id), 'A "%" in the term must not widen the match.');

        $underscore = Order::factory()->forShift($this->shift)->create([
            'user_id'     => $this->executor->id,
            'cost_center' => 'Корпус А_1',
        ]);
        $anyChar = Order::factory()->forShift($this->shift)->create([
            'user_id'     => $this->executor->id,
            'cost_center' => 'Корпус А31',
        ]);

        $response = $this->actingAs($this->executor)
            ->get(route('orders.index', ['view' => 'month', 'search' => 'А_1']));

        $ids = $this->listedOrderIds($response);

        $this->assertTrue($ids->contains($underscore->id), 'The underscore is part of the term.');
        $this->assertFalse($ids->contains($anyChar->id), 'A "_" in the term must not match any character.');
    }

    public function test_backdated_orders_never_appear_in_the_ordinary_list(): void
    {
        $retro = Order::factory()->forShift($this->shift)->create([
            'user_id'      => $this->executor->id,
            'is_backdated' => true,
        ]);

        $response = $this->actingAs($this->executor)->get(route('orders.index'));

        $response->assertOk();
        $this->assertFalse(
            $this->listedOrderIds($response)->contains($retro->id),
            'Retro orders live in their own module',
        );
    }

    public function test_deleted_orders_stay_out_of_the_list(): void
    {
        $order = Order::factory()->forShift($this->shift)->create(['user_id' => $this->executor->id]);
        $order->delete();

        $response = $this->actingAs($this->executor)->get(route('orders.index'));

        $response->assertOk();
        $this->assertFalse($this->listedOrderIds($response)->contains($order->id));
    }

    /**
     * The month view cut the month with whereMonth() against now() — both UTC.
     * A working day here runs to the 03:00 auto-close, so between midnight and
     * 03:00 Kyiv on the 1st the screen still showed the month that had ended,
     * and the orders taken in those three hours stayed out of the new month's
     * list for the rest of it.
     */
    public function test_the_month_view_is_the_kyiv_month(): void
    {
        // 01:00 Kyiv on 1 August — in UTC it is still 22:00 on 31 July.
        Carbon::setTestNow(Carbon::parse('2026-08-01 01:00:00', 'Europe/Kyiv'));

        $thisMonth = Order::factory()->forShift($this->shift)->create([
            'user_id'    => $this->executor->id,
            'created_at' => Carbon::parse('2026-08-01 01:30:00', 'Europe/Kyiv')->utc(),
        ]);
        $lastMonth = Order::factory()->forShift($this->shift)->create([
            'user_id'    => $this->executor->id,
            'created_at' => Carbon::parse('2026-07-20 12:00:00', 'Europe/Kyiv')->utc(),
        ]);

        $this->assertSame(
            7,
            $thisMonth->created_at->month,
            'Precondition: in UTC this order still belongs to July.',
        );

        $response = $this->actingAs($this->executor)->get(route('orders.index', ['view' => 'month']));

        $response->assertOk();
        $ids = $this->listedOrderIds($response);

        $this->assertTrue($ids->contains($thisMonth->id), 'An order taken at 01:00 on the 1st belongs to the new month.');
        $this->assertFalse($ids->contains($lastMonth->id), 'The previous month is over.');

        Carbon::setTestNow();
    }

    /** @return Collection<int, int> */
    private function listedOrderIds(TestResponse $response)
    {
        return collect($response->viewData('page')['props']['orders']['data'] ?? [])->pluck('id');
    }

    // ─── edit / update ──────────────────────────────────────────────

    public function test_a_finished_order_cannot_be_opened_for_editing(): void
    {
        $order = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'status'  => OrderStatus::CompletedIssued,
        ]);

        $response = $this->actingAs($this->executor)->get(route('orders.edit', $order));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_a_new_order_can_be_opened_for_editing(): void
    {
        $order = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'status'  => OrderStatus::New,
        ]);

        $this->actingAs($this->executor)
            ->get(route('orders.edit', $order))
            ->assertOk()
            // Same wiring guard as the create form above: the edit page narrows
            // its `CostCenterSelect` with this map, and `assertOk()` alone stays
            // green when the prop is dropped from the render array.
            ->assertInertia(fn (AssertableInertia $page) => $page->has('signatory_cost_centers'));
    }

    public function test_editing_replaces_the_items_and_recomputes_the_total(): void
    {
        $this->actingAs($this->executor)->post(route('orders.store'), $this->orderPayload());
        $order = Order::latest('id')->first();
        $this->assertEqualsWithDelta(10.00, (float) $order->total_cost, 0.001);

        $response = $this->actingAs($this->executor)->put(route('orders.update', $order), [
            'type'              => 'internal',
            'authorized_person' => 'Інший Підписант',
            'cost_center'       => 'Кафедра тестування',
            'version'           => $order->version,
            'items'             => [
                ['service_id' => $this->service->id, 'quantity' => 10],
            ],
        ]);

        $response->assertRedirect();

        $order->refresh();
        $this->assertSame('Інший Підписант', $order->authorized_person);
        $this->assertEqualsWithDelta(25.00, (float) $order->total_cost, 0.001, '10 × 2.50');
        $this->assertCount(1, $order->items);
    }

    // ─── destroy ────────────────────────────────────────────────────

    public function test_an_executor_cannot_delete_an_order(): void
    {
        $order = Order::factory()->forShift($this->shift)->create(['user_id' => $this->executor->id]);

        $this->actingAs($this->executor)
            ->delete(route('orders.destroy', $order))
            ->assertForbidden();

        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_an_admin_soft_deletes_the_order_and_its_items(): void
    {
        $this->actingAs($this->executor)->post(route('orders.store'), $this->orderPayload());
        $order = Order::latest('id')->first();
        $itemId = $order->items->first()->id;

        $response = $this->actingAs($this->admin)
            ->delete(route('orders.destroy', $order), ['reason' => 'Дубль']);

        $response->assertRedirect(route('orders.index'));
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->assertSoftDeleted('order_items', ['id' => $itemId]);
    }

    public function test_deletion_is_audited_with_the_reason(): void
    {
        $order = Order::factory()->forShift($this->shift)->create(['user_id' => $this->executor->id]);

        $this->actingAs($this->admin)
            ->delete(route('orders.destroy', $order), ['reason' => 'Помилка оператора']);

        $entry = AuditLog::where('event_type', 'order_deleted')->latest('id')->first();

        $this->assertNotNull($entry, 'Deleting an order must leave an audit trail');
        $this->assertSame($order->id, $entry->meta['order_id']);
        $this->assertSame('Помилка оператора', $entry->meta['reason']);
    }

    public function test_a_backdated_order_is_not_deletable_from_here(): void
    {
        $order = Order::factory()->forShift($this->shift)->create([
            'user_id'      => $this->executor->id,
            'is_backdated' => true,
        ]);

        $response = $this->actingAs($this->admin)->delete(route('orders.destroy', $order));

        $response->assertSessionHas('error');
        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
    }

    // ─── batch status ───────────────────────────────────────────────

    public function test_the_batch_endpoint_moves_every_eligible_order(): void
    {
        $orders = Order::factory()->count(3)->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'status'  => OrderStatus::New,
        ]);

        $response = $this->actingAs($this->executor)->patch(route('orders.batch-status'), [
            'order_ids' => $orders->pluck('id')->all(),
            'status'    => OrderStatus::InProgress->value,
        ]);

        $response->assertRedirect();

        foreach ($orders as $order) {
            $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
        }
    }

    public function test_the_batch_endpoint_skips_what_it_cannot_move_and_says_so(): void
    {
        $movable = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'status'  => OrderStatus::New,
        ]);
        $finished = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'status'  => OrderStatus::Cancelled,
        ]);

        $response = $this->actingAs($this->executor)->patch(route('orders.batch-status'), [
            'order_ids' => [$movable->id, $finished->id],
            'status'    => OrderStatus::InProgress->value,
        ]);

        $response->assertRedirect();
        $this->assertSame(OrderStatus::InProgress, $movable->fresh()->status);
        $this->assertSame(
            OrderStatus::Cancelled,
            $finished->fresh()->status,
            'A cancelled order must not be revived by a batch action',
        );
        $this->assertStringContainsString('пропущено: 1', session('success'));
    }

    /**
     * The batch endpoint checks canTransitionTo() on a copy read before the
     * transaction, then applies the status to a freshly locked row without
     * looking at it again (finding W-1, 2026-08-09 audit). Two requests
     * overlapping on one order both pass the stale check; the loser takes the
     * lock after the winner commits, sees completed_issued — and still writes
     * the status, deducts the stock and bumps the quota a second time.
     *
     * The listener below plays the winner: the moment the batch has read its
     * stale copy, the order is completed underneath it. Everything the loser
     * then does is damage, and all of it must not happen.
     */
    public function test_an_order_completed_by_a_concurrent_request_is_not_processed_again(): void
    {
        $category = InventoryCategory::factory()->create();
        $paper = InventoryItem::create([
            'inventory_category_id' => $category->id,
            'name'                  => 'Папір А4 80г',
            'unit'                  => 'арк',
            'current_quantity'      => 100,
            'empty_quantity'        => 0,
            'avg_cost'              => 2.00,
            'min_quantity'          => 0,
            'is_active'             => true,
        ]);

        $order = Order::factory()->forShift($this->shift)->create([
            'user_id'     => $this->executor->id,
            'type'        => OrderType::Internal,
            'status'      => OrderStatus::New,
            'cost_center' => null,
            'version'     => 1,
        ]);
        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'quantity'         => 10,
            'service_snapshot' => [
                'service_type'         => 'constructor',
                'constructor_snapshot' => [[
                    'inventory_item_id' => $paper->id,
                    'inventory_qty'     => 1,
                ]],
            ],
        ]);

        $raceWon = false;
        Order::retrieved(function (Order $retrieved) use ($order, &$raceWon): void {
            if ($raceWon || $retrieved->id !== $order->id) {
                return;
            }
            $raceWon = true;

            // The concurrent request commits between the stale read and the lock.
            DB::table('orders')->where('id', $order->id)->update([
                'status'  => OrderStatus::CompletedIssued->value,
                'version' => 2,
            ]);
        });

        $response = $this->actingAs($this->executor)->patch(route('orders.batch-status'), [
            'order_ids' => [$order->id],
            'status'    => OrderStatus::CompletedIssued->value,
        ]);

        $response->assertRedirect();
        $this->assertTrue($raceWon, 'Precondition: the concurrent completion must have fired.');

        $this->assertSame(
            0,
            InventoryMovement::where('type', 'auto_deduct')->count(),
            'The losing request must not deduct the stock a second time.',
        );
        $this->assertEqualsWithDelta(100.0, (float) $paper->fresh()->current_quantity, 0.001);

        $this->assertSame(
            0,
            OrderStatusHistory::where('order_id', $order->id)->count(),
            'A completed_issued → completed_issued entry means the blind write happened.',
        );

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::CompletedIssued, $fresh->status);
        $this->assertSame(2, $fresh->version, 'The version belongs to the winner; the loser must not bump it.');

        $this->assertStringContainsString('Оброблено: 0', session('success'));
        $this->assertStringContainsString('пропущено: 1', session('success'));
    }

    // ─── request received ───────────────────────────────────────────

    public function test_the_paper_request_flag_can_be_set_and_cleared(): void
    {
        $order = Order::factory()->forShift($this->shift)->create([
            'user_id'          => $this->executor->id,
            'type'             => OrderType::Internal,
            'request_received' => false,
        ]);

        $this->actingAs($this->executor)
            ->patch(route('orders.request', $order), ['request_received' => true])
            ->assertRedirect();

        $order->refresh();
        $this->assertTrue($order->request_received);
        $this->assertNotNull($order->request_received_at);

        $this->actingAs($this->executor)
            ->patch(route('orders.request', $order), ['request_received' => false]);

        $order->refresh();
        $this->assertFalse($order->request_received);
        $this->assertNull($order->request_received_at);
    }

    public function test_the_paper_request_flag_is_meaningless_for_commercial_orders(): void
    {
        $order = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'type'    => OrderType::Commercial,
        ]);

        $response = $this->actingAs($this->executor)
            ->patch(route('orders.request', $order), ['request_received' => true]);

        $response->assertSessionHas('error');
        $this->assertFalse($order->fresh()->request_received);
    }

    // ─── approval email ─────────────────────────────────────────────

    public function test_approval_cannot_be_sent_for_a_commercial_order(): void
    {
        $order = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'type'    => OrderType::Commercial,
        ]);

        $response = $this->actingAs($this->executor)->post(route('orders.send-approval', $order));

        $response->assertSessionHas('error');
        Notification::assertNothingSent();
    }

    public function test_approval_cannot_be_sent_when_the_signatory_has_no_email(): void
    {
        UniversityRef::factory()->create([
            'full_name' => 'Без Пошти',
            'email'     => null,
            'is_active' => true,
        ]);

        $order = Order::factory()->forShift($this->shift)->create([
            'user_id'           => $this->executor->id,
            'type'              => OrderType::Internal,
            'authorized_person' => 'Без Пошти',
        ]);

        $response = $this->actingAs($this->executor)->post(route('orders.send-approval', $order));

        $response->assertSessionHas('error');
        Notification::assertNothingSent();
    }

    /**
     * sendApproval() checked the type and the signatory but not the status
     * (finding I-1, 2026-08-09 audit): a cancelled order could still mail a
     * live approval link, and the click would stamp request_received on it.
     * A finished order has nothing left to approve — for either terminal
     * status the request must be refused before anything is queued.
     */
    public function test_approval_cannot_be_sent_for_a_cancelled_order(): void
    {
        UniversityRef::factory()->create([
            'full_name' => 'Дійсний Підписант',
            'email'     => 'signatory@example.edu',
            'is_active' => true,
        ]);

        $order = Order::factory()->forShift($this->shift)->create([
            'user_id'           => $this->executor->id,
            'type'              => OrderType::Internal,
            'authorized_person' => 'Дійсний Підписант',
            'status'            => OrderStatus::Cancelled,
        ]);

        $response = $this->actingAs($this->executor)->post(route('orders.send-approval', $order));

        $response->assertSessionHas('error');
        Notification::assertNothingSent();
    }

    public function test_approval_cannot_be_sent_for_an_issued_order(): void
    {
        UniversityRef::factory()->create([
            'full_name' => 'Дійсний Підписант',
            'email'     => 'signatory@example.edu',
            'is_active' => true,
        ]);

        $order = Order::factory()->forShift($this->shift)->create([
            'user_id'           => $this->executor->id,
            'type'              => OrderType::Internal,
            'authorized_person' => 'Дійсний Підписант',
            'status'            => OrderStatus::CompletedIssued,
        ]);

        $response = $this->actingAs($this->executor)->post(route('orders.send-approval', $order));

        $response->assertSessionHas('error');
        Notification::assertNothingSent();
    }

    /**
     * The letter is the moment the order leaves the building, and until now it
     * was the one moment nothing asked whether the signatory may cover what it
     * contains. It still goes — refusing here would contradict the owner's
     * decision of 2026-07-31 and strand an operator whose signatory changed
     * group — but the operator is told.
     */
    public function test_sending_an_order_outside_the_signatory_categories_warns_and_still_queues(): void
    {
        $allowed = ServiceCategory::factory()->create(['available_for' => ['internal']]);
        $ordered = ServiceCategory::factory()->create([
            'name'          => 'Палітурні роботи',
            'available_for' => ['internal'],
        ]);

        $group = SignatoryGroup::factory()->create(['name' => 'Деканат', 'daily_limit' => null]);
        $group->categories()->sync([$allowed->id]);

        UniversityRef::factory()->create([
            'full_name'          => 'Іваненко Іван Іванович',
            'email'              => 'approver@example.edu',
            'signatory_group_id' => $group->id,
            'is_active'          => true,
        ]);

        $service = Service::factory()->create([
            'name'                => 'Прошивка на пружину',
            'service_category_id' => $ordered->id,
            'type'                => 'static',
            'is_active'           => true,
        ]);

        $order = Order::factory()->internal()->create([
            'authorized_person' => 'Іваненко Іван Іванович',
            'status'            => 'new',
        ]);
        OrderItem::factory()->create([
            'order_id'   => $order->id,
            'service_id' => $service->id,
            'quantity'   => 5,
        ]);

        $this->actingAs($this->executor)
            ->post(route('orders.send-approval', $order))
            ->assertRedirect();

        $this->assertNotNull(session('success'), 'The letter still goes out');
        $this->assertStringContainsString('Прошивка на пружину', session('warning'));
        $this->assertStringContainsString('Палітурні роботи', session('warning'));
    }

    /** An order the signatory fully covers says nothing, or the warning becomes noise. */
    public function test_sending_an_order_inside_the_signatory_categories_says_nothing(): void
    {
        $allowed = ServiceCategory::factory()->create(['available_for' => ['internal']]);

        $group = SignatoryGroup::factory()->create(['name' => 'Деканат', 'daily_limit' => null]);
        $group->categories()->sync([$allowed->id]);

        UniversityRef::factory()->create([
            'full_name'          => 'Іваненко Іван Іванович',
            'email'              => 'approver@example.edu',
            'signatory_group_id' => $group->id,
            'is_active'          => true,
        ]);

        $service = Service::factory()->create([
            'service_category_id' => $allowed->id,
            'type'                => 'static',
            'is_active'           => true,
        ]);

        $order = Order::factory()->internal()->create([
            'authorized_person' => 'Іваненко Іван Іванович',
            'status'            => 'new',
        ]);
        OrderItem::factory()->create([
            'order_id'   => $order->id,
            'service_id' => $service->id,
            'quantity'   => 5,
        ]);

        $this->actingAs($this->executor)
            ->post(route('orders.send-approval', $order))
            ->assertRedirect();

        $this->assertNull(session('warning'));
    }

    // ─── Riso originals on the ordinary counter path (D16) ──────────
    //
    // The audit item read «чекає на перше нове Різо-замовлення»: of 119 live
    // Riso lines exactly one carries the `originals` key, and that one is in a
    // deleted order — the key arrived in commit `0cbe9ab` on 31.07.2026, a day
    // after the newest Riso card was made. So «подивитись на картку» was
    // waiting for an event nobody controls.
    //
    // Two of the three links were already pinned: the retro path walks
    // HTTP → FormRequest → builder → snapshot in `BackdatedOrderWorkflowTest`,
    // and the chip itself is `risoOptionLabels.spec.js`. The third — **this**
    // path, the counter where the department actually works — was not, and
    // that is the one a new Riso order would have exercised.
    //
    // Asked as a test instead (round 31), the way round 26 closed four others.

    public function test_riso_originals_survive_the_ordinary_order_path(): void
    {
        $riso = $this->risoService();

        $this->actingAs($this->executor)
            ->post(route('orders.store'), $this->orderPayload([
                'items' => [[
                    'service_id' => $riso->id,
                    // 2 originals × 5 A4 copies each
                    'quantity'       => 10,
                    'riso_format'    => 'A4',
                    'riso_sides'     => 1,
                    'riso_originals' => 2,
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $params = OrderItem::where('service_id', $riso->id)->sole()->service_snapshot['riso_params'];

        $this->assertSame(2, $params['originals']);

        // The rounding R14-1 is about: 2 × ceil(5/2) = 6, not ceil(10/2) = 5.
        // Getting `originals` through and then halving the whole run would
        // pass the assertion above and still price a different job.
        $this->assertSame(6, $params['sheets_a3']);
    }

    /**
     * And the card page hands the chip its input.
     *
     * `risoOptionLabels` renders «2 ориг.» from `riso_params`; this is the
     * only link between the stored snapshot and that function, and it is the
     * one the item asked to «побачити очима».
     */
    public function test_the_order_card_carries_the_originals_the_chip_prints(): void
    {
        $riso = $this->risoService();

        $this->actingAs($this->executor)
            ->post(route('orders.store'), $this->orderPayload([
                'items' => [[
                    'service_id'     => $riso->id,
                    'quantity'       => 10,
                    'riso_format'    => 'A4',
                    'riso_sides'     => 1,
                    'riso_originals' => 2,
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $order = OrderItem::where('service_id', $riso->id)->sole()->order;

        $this->actingAs($this->executor)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.items.0.service_snapshot.riso_params.originals', 2)
                ->where('order.items.0.service_snapshot.riso_params.format', 'A4'));
    }

    private function risoService(): Service
    {
        InventoryItem::factory()->create([
            'name'             => 'Папір А3 80 г/м²',
            'avg_cost'         => 1.00,
            'current_quantity' => 1000,
            'is_active'        => true,
        ]);

        RisoPriceTier::query()->forceDelete();
        RisoPriceTier::create(['min_qty' => 1, 'max_qty' => null, 'cost_per_copy' => 0.50]);

        return Service::factory()->create([
            'name'                => 'Тиражування (RISO)',
            'type'                => 'riso',
            'service_category_id' => ServiceCategory::factory()->create([
                'name'          => 'Тиражування',
                'available_for' => ['internal', 'commercial'],
            ])->id,
        ]);
    }

    // ─── auth ───────────────────────────────────────────────────────

    public function test_the_whole_module_needs_authentication(): void
    {
        $order = Order::factory()->forShift($this->shift)->create(['user_id' => $this->executor->id]);

        $this->get(route('orders.index'))->assertRedirect(route('login'));
        $this->get(route('orders.show', $order))->assertRedirect(route('login'));
        $this->post(route('orders.store'), [])->assertRedirect(route('login'));
    }
}
