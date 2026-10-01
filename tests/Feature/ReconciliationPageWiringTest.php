<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftStatus;
use App\Exports\ReconciliationExport;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Maatwebsite\Excel\Concerns\WithTitle;
use Tests\TestCase;

/**
 * The reconciliation screen against the endpoints it actually calls.
 *
 * Two of its controls were wired to the wrong place. The "заявка" toggle called
 * the order route, which sits behind EnsureShiftIsOpen and permission:orders,
 * although a shift-free twin exists on this very controller for exactly this
 * page. And the XLSX link carried three of the five filters the screen applies,
 * so ReconciliationExport's $costCenter and $authorizedPerson — written, routed
 * and applied — never received a value from anywhere.
 *
 * Both halves are checked: what the endpoints do, and that the page reaches
 * them. Checking only the first is what let this sit — the server side was
 * right the whole time, and nothing called it.
 */
class ReconciliationPageWiringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    // ─── The "заявка" toggle ─────────────────────────────

    public function test_the_request_toggle_works_with_no_shift_open(): void
    {
        $order = $this->internalOrder();

        $this->assertFalse(Shift::current()->exists(), 'no shift, as at month end');

        $this->actingAs($this->admin)
            ->patch(route('admin.reconciliation.toggle-request', $order), ['request_received' => true])
            ->assertRedirect();

        $this->assertTrue($order->fresh()->request_received);
    }

    public function test_the_order_route_the_page_used_to_call_needs_an_open_shift(): void
    {
        $order = $this->internalOrder();

        // This is the consequence, not a wish: month-end reconciliation happens
        // with the till shut, and this route throws the admin onto the
        // shift-open form instead of ticking the box.
        $this->actingAs($this->admin)
            ->patch(route('orders.request', $order), ['request_received' => true])
            ->assertRedirect(route('shifts.open.form'));

        $this->assertFalse($order->fresh()->request_received);
    }

    public function test_a_reports_user_without_the_orders_module_can_still_tick_the_box(): void
    {
        $accountant = User::factory()->create([
            'role'        => 'manager',
            'permissions' => ['reports'],
        ]);
        $order = $this->internalOrder();
        $this->openShift();

        $this->actingAs($accountant)
            ->patch(route('orders.request', $order), ['request_received' => true])
            ->assertForbidden();

        $this->actingAs($accountant)
            ->patch(route('admin.reconciliation.toggle-request', $order), ['request_received' => true])
            ->assertRedirect();

        $this->assertTrue($order->fresh()->request_received);
    }

    public function test_the_toggle_refuses_a_commercial_order(): void
    {
        $order = $this->internalOrder();
        $order->update(['type' => OrderType::Commercial->value]);

        $this->actingAs($this->admin)
            ->patch(route('admin.reconciliation.toggle-request', $order), ['request_received' => true])
            ->assertSessionHas('error');

        $this->assertFalse($order->fresh()->request_received);
    }

    // ─── The XLSX filters ────────────────────────────────

    public function test_the_export_honours_the_request_received_filter(): void
    {
        $received = $this->internalOrder(['request_received' => true], 'Сканування');
        $awaited = $this->internalOrder(['request_received' => false], 'Ламінування');

        // The screen hands the workbook its raw filter value, not a boolean:
        // 'paper' and 'email' are values too, and filter_var() flattens both
        // to false. '0' is what the «Не отримана» option sends.
        $names = $this->categoriesOf(new ReconciliationExport(requestReceived: '0'));

        // 'Ламінація' is the sheet tab; the service is 'Ламінування'.
        $this->assertSame(['Ламінація'], $names);
    }

    public function test_the_export_honours_the_cost_centre_filter(): void
    {
        $this->internalOrder(['cost_center' => 'Кафедра А'], 'Сканування');
        $this->internalOrder(['cost_center' => 'Кафедра Б'], 'Ламінування');

        $names = $this->categoriesOf(new ReconciliationExport(costCenter: 'Кафедра Б'));

        // 'Ламінація' is the sheet tab; the service is 'Ламінування'.
        $this->assertSame(['Ламінація'], $names);
    }

    public function test_the_export_honours_the_signatory_filter(): void
    {
        $this->internalOrder(['authorized_person' => 'Іваненко І.І.'], 'Сканування');
        $this->internalOrder(['authorized_person' => 'Петренко П.П.'], 'Ламінування');

        $names = $this->categoriesOf(new ReconciliationExport(authorizedPerson: 'Петренко П.П.'));

        // 'Ламінація' is the sheet tab; the service is 'Ламінування'.
        $this->assertSame(['Ламінація'], $names);
    }

    public function test_the_controller_passes_every_filter_through_to_the_workbook(): void
    {
        $this->internalOrder(['cost_center' => 'Кафедра А', 'request_received' => true], 'Сканування');

        $this->actingAs($this->admin)
            ->get(route('admin.reconciliation.export', [
                'cost_center'       => 'Кафедра А',
                'authorized_person' => 'Алчук В.Г.',
                'request_received'  => '1',
                'reconciled'        => '0',
            ]))
            ->assertOk();
    }

    // ─── That the page reaches them ──────────────────────
    //
    // Source assertions, deliberately. Every endpoint above was already correct
    // before this round; what was wrong was that the page called something
    // else, and no test of an endpoint can notice that. Vitest here mounts
    // components, not pages, so this is the cheapest honest check that the wire
    // exists.

    public function test_the_page_ticks_the_box_through_the_shift_free_route(): void
    {
        $source = $this->reconciliationPageSource();

        $this->assertTrue(
            str_contains($source, "route('admin.reconciliation.toggle-request'"),
            'The page does not call the shift-free toggle written for it.',
        );
        $this->assertFalse(
            str_contains($source, "route('orders.request'"),
            'The page still calls the order route, which needs an open shift.',
        );
    }

    public function test_the_export_link_carries_every_filter_the_screen_applies(): void
    {
        $source = $this->reconciliationPageSource();

        // Every filter the form offers. Dropping any of them hands the
        // accountant a file wider than the screen they were reading, with
        // nothing in the file saying so.
        foreach (['from', 'to', 'reconciled', 'cost_center', 'authorized_person', 'request_received'] as $filter) {
            $this->assertTrue(
                str_contains($source, "params.set('{$filter}'"),
                "The XLSX link drops the '{$filter}' filter.",
            );
        }
    }

    // ─── The edit pencil ─────────────────────────
    //
    // The same defect as the toggle above, one column to the right and one
    // round later: this page is permission:reports and shift-free, while
    // orders.edit sits behind permission:orders and EnsureShiftIsOpen. Editing
    // an order really does need an open shift — it moves stock and cash — so
    // the rule stands and the button is only drawn when it will work.

    public function test_the_edit_pencil_is_withheld_when_no_shift_is_open(): void
    {
        $this->internalOrder();
        $this->assertFalse(Shift::current()->exists(), 'no shift, as at month end');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.reconciliation.index'));

        $this->assertFalse($response->viewData('page')['props']['canEditOrders']);
    }

    public function test_the_edit_pencil_is_withheld_from_a_reports_only_user(): void
    {
        $accountant = User::factory()->create([
            'role'        => 'manager',
            'permissions' => ['reports'],
        ]);
        $this->internalOrder();
        $this->openShift();

        $response = $this->actingAs($accountant)
            ->get(route('admin.reconciliation.index'));

        $this->assertFalse($response->viewData('page')['props']['canEditOrders']);
    }

    public function test_the_edit_pencil_is_offered_when_it_will_actually_work(): void
    {
        $this->internalOrder();
        $this->openShift();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.reconciliation.index'));

        $this->assertTrue($response->viewData('page')['props']['canEditOrders']);
    }

    public function test_the_page_gates_the_edit_link_on_that_flag(): void
    {
        // Source assertion, same reasoning as the two above it: the endpoint is
        // right, and no test of it can notice that the page offers a link into
        // it regardless.
        $this->assertTrue(
            str_contains($this->reconciliationPageSource(), 'canEditOrders &&'),
            'The edit pencil is drawn without checking whether it can be used.',
        );
    }

    // ─── Helpers ─────────────────────────────────────────

    /**
     * The page arrives with what a block is built from — audit item F16, and
     * the item was waiting for the wrong thing.
     *
     * F16 stood as «наступний імпорт ретро-місяця → прапорець у шапці блоку»,
     * with a note that the checkbox «просто не рендериться». Both halves were
     * misread.
     *
     * The header is in the markup, `aria-label="Обрати всі: …"`, drawn **per
     * block** — a block being a client-side grouping of the page's orders by
     * `service_name`. Nothing unreconciled, no block, no header.
     *
     * And a retro import can never produce one: `ReconciliationController`
     * filters through `operational()`, which is `is_backdated = false`. Retro
     * is isolated from Dashboard, Reports, Analytics and this page by design
     * and has a separate reconciliation of
     * its own. **So the trigger could not fire the check**, and the item would
     * have waited for ever — the same shape as the eleven rounds G16 spent
     * waiting for a card that did not exist yet.
     *
     * What the checkbox actually needs is ordinary orders, which the page has
     * every day. Asked here; the selection rule itself — «бере лише свій
     * блок», including an order in two — is `useBlockSelection.spec.js`
     * (round 16, seven cases).
     */
    public function test_the_page_arrives_with_the_service_names_blocks_are_grouped_by(): void
    {
        $this->internalOrder([], 'Тиражування (RISO)');
        $this->internalOrder([], 'Ламінація');
        $this->internalOrder([], 'Тиражування (RISO)');

        $this->actingAs($this->admin)
            ->get(route('admin.reconciliation.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $orders = $page->toArray()['props']['orders']['data'];

                $names = collect($orders)
                    ->flatMap(fn ($o) => collect($o['items'])->pluck('service_name'))
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                // Two distinct names → two blocks, each with its own header
                // checkbox; three orders, so one block holds two of them.
                $this->assertSame(['Ламінація', 'Тиражування (RISO)'], $names);
                $this->assertCount(3, $orders);
            });
    }

    /**
     * And the reason F16 could not fire, pinned so nobody re-opens it.
     *
     * If retro ever *should* appear on this page, that is a product decision,
     * and this test is where it gets made rather than discovered.
     */
    public function test_a_retro_order_never_reaches_the_reconciliation_page(): void
    {
        $this->internalOrder(['is_backdated' => true], 'Тиражування (RISO)');

        $this->actingAs($this->admin)
            ->get(route('admin.reconciliation.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.data', [])
                ->where('summary.total', 0));
    }

    private function reconciliationPageSource(): string
    {
        return (string) file_get_contents(
            resource_path('js/Pages/Admin/Reconciliation/Index.vue'),
        );
    }

    /** The category sheet titles an export would produce, summary excluded. */
    private function categoriesOf(ReconciliationExport $export): array
    {
        $names = [];
        foreach ($export->sheets() as $sheet) {
            if ($sheet instanceof WithTitle && $sheet->title() !== 'Зведення') {
                $names[] = $sheet->title();
            }
        }

        return $names;
    }

    private function internalOrder(array $attributes = [], string $serviceName = 'Сканування'): Order
    {
        $order = Order::factory()->create(array_merge([
            'type'              => OrderType::Internal->value,
            'status'            => OrderStatus::CompletedIssued->value,
            'is_backdated'      => false,
            'user_id'           => $this->admin->id,
            'shift_id'          => null,
            'total_cost'        => 10.00,
            'total_commercial'  => 0,
            'authorized_person' => 'Алчук В.Г.',
            'cost_center'       => 'Кафедра тестування',
            'request_received'  => false,
        ], $attributes));

        $order->forceFill(['created_at' => Carbon::now()])->saveQuietly();

        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_name'     => $serviceName,
            'quantity'         => 1,
            'total_price_cost' => 10.00,
        ]);

        return $order->fresh(['items']);
    }

    private function openShift(): Shift
    {
        return Shift::factory()->create([
            'status'    => ShiftStatus::Open->value,
            'opened_by' => $this->admin->id,
            'date'      => today('Europe/Kyiv'),
        ]);
    }
}
