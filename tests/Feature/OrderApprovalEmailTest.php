<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\UniversityRef;
use App\Models\User;
use App\Notifications\OrderCreatedNotification;
use App\Services\ApprovalItemsPresenter;
use App\Services\BrochurePricingService;
use App\Services\DiplomaPricingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The approval email — the one piece of this system that leaves the building.
 *
 * 76 instructions at 0% through six audit rounds, and three findings have
 * already landed on it from the outside: the token leaking into Telegram via
 * fullUrl(), signedRoute() with no expiry, and toMail() minting a
 * fresh token on every queue retry. What the message itself contains and
 * how long its links live had never been asserted.
 */
class OrderApprovalEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private UniversityRef $signatory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create(['role' => 'executor']);
        $this->signatory = UniversityRef::factory()->create([
            'full_name' => 'Іваненко Іван Іванович',
            'email'     => 'approver@example.edu',
            'is_active' => true,
        ]);
    }

    private function order(): Order
    {
        $order = Order::factory()->create([
            'user_id'           => $this->operator->id,
            'type'              => 'internal',
            'authorized_person' => $this->signatory->full_name,
        ]);

        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_name'     => 'Друк А4',
            'quantity'         => 25,
            'total_price_cost' => 42.50,
        ]);

        return $order->load('items');
    }

    private function mail(Order $order): MailMessage
    {
        return (new OrderCreatedNotification($order))->toMail($this->signatory);
    }

    public function test_sending_creates_a_pending_approval_for_the_signatory(): void
    {
        $order = $this->order();

        $this->mail($order);

        $approval = OrderApproval::sole();
        $this->assertSame($order->id, $approval->order_id);
        $this->assertSame('pending', $approval->status);
        $this->assertSame($this->signatory->email, $approval->signatory_email);
        $this->assertSame($order->authorized_person, $approval->signatory_name);
        $this->assertSame(64, strlen($approval->token));
    }

    /**
     * A second send supersedes the first, so only one live link exists at a
     * time. Two live tokens would mean two people could each hold a valid
     * approval for the same order.
     */
    public function test_resending_supersedes_the_previous_link(): void
    {
        $order = $this->order();

        $this->mail($order);
        $first = OrderApproval::sole();

        $this->mail($order);

        $this->assertSame('superseded', $first->fresh()->status);
        $this->assertSame(1, OrderApproval::where('status', 'pending')->count());
    }

    /**
     * M-2 was closed by remembering the approval on a property of the
     * notification — and that is not what a queue retry replays.
     *
     * A failed job is pushed back with the payload it was dispatched with — the
     * bytes serialised in the web request, before toMail() ever ran. Each
     * attempt unserialises that same payload afresh, so a property assigned on
     * one attempt does not exist on the next. That is what the two
     * `unserialize($payload)` calls below are: not a round trip of the object
     * after use, but the two attempts replaying one dispatch.
     *
     * Before the token moved into the constructor this produced a second row
     * and superseded the first — the "посилання замінено" page for a signatory
     * holding a link that had in fact been delivered, since an SMTP timeout
     * fails the job after the mail is already out.
     */
    public function test_a_retry_of_the_same_send_keeps_the_link_that_went_out(): void
    {
        $order = $this->order();

        // What the queue stores at dispatch.
        $payload = serialize(new OrderCreatedNotification($order));

        unserialize($payload)->toMail($this->signatory);

        $first = OrderApproval::sole();

        // The worker's second attempt starts from the same bytes.
        unserialize($payload)->toMail($this->signatory);

        $this->assertSame(1, OrderApproval::count(), 'A retry is the same request, not a new one');
        $this->assertSame('pending', $first->fresh()->status);
        $this->assertSame($first->token, OrderApproval::sole()->token);
    }

    /**
     * The TTL is a setting an admin can change, and it still has to reach both
     * the row and the signature. What changed is that they are no longer the
     * same instant.
     *
     * This test used to assert they were, and gave the reason: «a link that
     * outlives its row, or vice versa, is a link whose expiry nobody can
     * state». The half nobody tried is what the signatory sees one minute
     * later — `signed` refuses the request before ApprovalController runs, so
     * «Посилання протухло» could never render for the link the system mails
     *. The row decides whether the approval can act; the
     * signature only proves the link was not forged, and outlives the row by
     * the grace window so the refusal can be explained in Ukrainian.
     */
    public function test_the_ttl_setting_governs_both_the_row_and_the_signature(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00', 'UTC'));
        Setting::setValue('approval_ttl_hours', 5);

        $order = $this->order();
        $mail = $this->mail($order);

        $approval = OrderApproval::sole();
        $this->assertSame(
            '2026-07-29 17:00:00',
            $approval->expires_at->utc()->format('Y-m-d H:i:s'),
        );

        $url = $mail->viewData['approveUrl'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame(
            (string) $approval->linkValidUntil()->getTimestamp(),
            (string) $query['expires'],
            'The signature does not follow the TTL setting the row was built from.',
        );

        $this->assertTrue(
            $approval->linkValidUntil()->greaterThan($approval->expires_at),
            'The signature dies with the row again, so the expiry page cannot render.',
        );

        Carbon::setTestNow();
    }

    /**
     * The link out of a real message, one minute after its row expired.
     *
     * This is the assertion the round turned on: not a URL a test built, but
     * the one `toMail()` put in front of a signatory. Before R33-1 it answered
     * 403 — the signature had died with the row, so `signed` refused it and
     * ApprovalController, which owns the only Ukrainian explanation of what
     * happened, never ran.
     */
    public function test_the_mailed_link_explains_itself_after_its_row_expires(): void
    {
        $order = $this->order();
        $url = $this->mail($order)->viewData['approveUrl'];

        $approval = OrderApproval::sole();

        Carbon::setTestNow($approval->expires_at->copy()->addMinute());

        $this->get($url)
            ->assertOk()
            ->assertSee('Посилання протухло');

        Carbon::setTestNow();
    }

    /**
     * Both links must be signed and must actually verify — the approval routes
     * have no other authentication.
     */
    public function test_both_links_are_signed_and_verify(): void
    {
        $order = $this->order();
        $mail = $this->mail($order);

        foreach (['approveUrl', 'rejectUrl'] as $key) {
            $url = $mail->viewData[$key];

            $this->assertStringContainsString('signature=', $url, "{$key} is not signed.");

            $request = Request::create($url);
            $this->assertTrue(
                URL::hasValidSignature($request),
                "{$key} does not pass signature validation.",
            );
        }
    }

    public function test_a_tampered_link_does_not_verify(): void
    {
        $order = $this->order();
        $url = $this->mail($order)->viewData['approveUrl'];

        $tampered = str_replace('action=approve', 'action=reject', $url);

        $this->assertFalse(
            URL::hasValidSignature(Request::create($tampered)),
            'Swapping approve for reject kept the signature valid.',
        );
    }

    /**
     * The signatory decides on what the email says. If the order has items,
     * the message has to name them and their quantities.
     */
    public function test_the_message_lists_what_is_being_approved(): void
    {
        $order = $this->order();
        $mail = $this->mail($order);

        $this->assertStringContainsString($order->order_number, $mail->subject);
        $this->assertSame('Іваненко Іван Іванович', $mail->viewData['signatoryName']);
        $this->assertStringContainsString('Друк А4', $mail->viewData['itemsSummary']);
        $this->assertStringContainsString('25', $mail->viewData['itemsSummary']);

        $item = $mail->viewData['itemsByCategory'][0]['items'][0];
        $this->assertSame('Друк А4', $item['name']);
        $this->assertSame(25, $item['quantity']);
        $this->assertArrayNotHasKey('cost', $item, 'Money is not the signatory\'s to see (owner, 2026-08-20)');
    }

    public function test_an_order_with_no_items_still_produces_a_message(): void
    {
        $order = Order::factory()->create([
            'user_id'           => $this->operator->id,
            'type'              => 'internal',
            'authorized_person' => $this->signatory->full_name,
        ]);

        $mail = $this->mail($order->load('items'));

        $this->assertSame('Деталі в системі', $mail->viewData['itemsSummary']);
        $this->assertSame([], $mail->viewData['itemsByCategory']);
    }

    /**
     * "Заповненість" is an internal-only pricing dimension — it is how much
     * toner the page uses, not something a signatory approves. The detail line
     * drops it for internal orders.
     */
    public function test_internal_orders_hide_the_coverage_parameter(): void
    {
        $order = Order::factory()->create([
            'user_id'           => $this->operator->id,
            'type'              => 'internal',
            'authorized_person' => $this->signatory->full_name,
        ]);

        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_name'     => 'Друк А4',
            'quantity'         => 10,
            'service_snapshot' => ['constructor_snapshot' => [
                ['group_name' => 'Формат',       'option_name' => 'А4: 1+0'],
                ['group_name' => 'Заповненість', 'option_name' => 'до 20%'],
            ]],
        ]);

        $details = $this->mail($order->load('items'))->viewData['itemsByCategory'][0]['items'][0]['details'];

        $this->assertStringContainsString('1+0', $details, 'The format prefix should be stripped, the value kept.');
        $this->assertStringNotContainsString('до 20%', $details);
    }

    // ─── the two services the detail column never described ──────

    /**
     * formatItemsDetailed() had branches for constructor_snapshot and
     * riso_params only. A brochure or a diploma reached the signatory as a bare
     * "Послуга × 1" with an empty details column — a line to sign with no
     * parameters on it, while the same snapshot fills eight columns of the
     * accounting workbook.
     *
     * Built with the real pricing service, as the round 14 export tests are:
     * a snapshot the test writes itself would only prove the reader reads what
     * the test wrote.
     */
    public function test_a_brochure_line_names_the_format_and_both_papers(): void
    {
        $this->clickCosts();
        $cover = $this->paper('Крейда 250 г/м²', 4.10);
        $block = $this->paper('Офсет 80 г/м²', 0.55);

        $snapshot = app(BrochurePricingService::class)->buildSnapshot(
            $this->pricedService('Брошура (скоба)', 'brochure'),
            [
                'format'         => 'А4',
                'cover_paper_id' => $cover->id,
                'cover_mode'     => '4+0',
                'block_paper_id' => $block->id,
                'block_entries'  => [['mode' => '1+0', 'sheets' => 5], ['mode' => '4+0', 'sheets' => 2]],
            ],
            quantity: 10,
        );

        $details = $this->detailsFor('Брошура (скоба)', $snapshot);

        $this->assertSame(
            'А4 · обкладинка: Крейда 250 г/м², 4+0 · блок: Офсет 80 г/м², 7 арк.',
            $details,
        );
    }

    public function test_a_diploma_line_names_the_counts_being_approved(): void
    {
        $this->clickCosts();
        $this->paper('Папір А4 160 г/м²', 1.60);
        $this->paper('Папір А3 160 г/м²', 3.20);
        $this->paper('Папір А4 80 г/м²', 0.50);

        $snapshot = app(DiplomaPricingService::class)->buildSnapshot(
            $this->pricedService('Дипломи/Додатки', 'diploma'),
            [
                'diplomas'    => ['qty' => 12],
                'supplements' => [[
                    'type'   => 'bachelor',
                    'label'  => 'Бакалавр',
                    'qty'    => 2,
                    'blocks' => [['sheets' => 4, 'mode' => '4+4'], ['sheets' => 1, 'mode' => '4+0']],
                ]],
                'academic_records' => ['qty' => 3],
                'copies'           => [
                    'diploma_copies'    => ['qty' => 7, 'mode' => '1+0'],
                    'supplement_copies' => ['qty' => 2, 'blocks' => [['sheets' => 6, 'mode' => '1+1']]],
                ],
            ],
            quantity: 1,
        );

        $details = $this->detailsFor('Дипломи/Додатки', $snapshot);

        $this->assertSame(
            'дипломи: 12 · додатки: 2 (10 арк.) · академдовідки: 3 · копії диплома: 7 · копії додатка: 2 (12 арк.)',
            $details,
        );
    }

    /**
     * A diploma order with only diplomas on it must not carry the empty halves
     * of the line — "додатки: 0 · академдовідки: 0" is noise to sign around.
     */
    public function test_a_diploma_line_leaves_out_what_was_not_ordered(): void
    {
        $this->clickCosts();
        $this->paper('Папір А4 160 г/м²', 1.60);
        $this->paper('Папір А3 160 г/м²', 3.20);
        $this->paper('Папір А4 80 г/м²', 0.50);

        $snapshot = app(DiplomaPricingService::class)->buildSnapshot(
            $this->pricedService('Дипломи/Додатки', 'diploma'),
            ['diplomas' => ['qty' => 4]],
            quantity: 1,
        );

        $this->assertSame('дипломи: 4', $this->detailsFor('Дипломи/Додатки', $snapshot));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function detailsFor(string $serviceName, array $snapshot): string
    {
        $order = Order::factory()->create([
            'user_id'           => $this->operator->id,
            'type'              => 'internal',
            'authorized_person' => $this->signatory->full_name,
        ]);

        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_name'     => $serviceName,
            'quantity'         => 1,
            'service_snapshot' => $snapshot,
        ]);

        return $this->mail($order->load('items'))->viewData['itemsByCategory'][0]['items'][0]['details'];
    }

    private function pricedService(string $name, string $type): Service
    {
        return Service::factory()->create([
            'name'                => $name,
            'type'                => $type,
            'service_category_id' => ServiceCategory::factory()->create(['name' => $name])->id,
        ]);
    }

    private function paper(string $name, float $avgCost): InventoryItem
    {
        return InventoryItem::factory()->create([
            'name'                  => $name,
            'inventory_category_id' => InventoryCategory::factory()->create()->id,
            'avg_cost'              => $avgCost,
            'current_quantity'      => 1000,
            'is_active'             => true,
        ]);
    }

    private function clickCosts(): void
    {
        Material::factory()->create(['counter_type' => 'bw', 'click_cost' => 0.12, 'is_active' => true]);
        Material::factory()->create(['counter_type' => 'color', 'click_cost' => 0.60, 'is_active' => true]);
    }

    // ─── what a send that never arrives leaves behind ────

    /**
     * Reproduction, before the fix: the superseding happens when the message is
     * *built*, and building happens on the worker. A resend that never leaves
     * the building therefore still kills the link that did.
     *
     * The signatory is holding a delivered, working link. The operator presses
     * "Надіслати повторно", SMTP is down, the job exhausts its retries. Old link
     * dead, new link never sent, and the order screen reads "⏳ Очікує
     * погодження" — which is the R13-6 defect again: the screen states a
     * delivery nobody attempted successfully.
     */
    public function test_a_resend_that_never_goes_out_gives_the_delivered_link_back(): void
    {
        $order = $this->order();

        // Send one: delivered.
        $this->mail($order);
        $delivered = OrderApproval::sole();

        // Send two: built on the worker, then the mail never goes out.
        $second = new OrderCreatedNotification($order);
        $second->toMail($this->signatory);
        $second->failed(new \RuntimeException('SMTP refused'));

        $this->assertSame(
            'pending',
            $delivered->fresh()->status,
            'A resend that failed took the signatory\'s working link with it.',
        );

        $this->assertNull(
            OrderApproval::where('order_id', $order->id)->where('status', 'pending')->get()
                ->firstWhere('id', '!=', $delivered->id),
            'The link that was never delivered is still standing as pending.',
        );

        $this->assertSame(
            $delivered->id,
            $order->fresh()->latestApproval->id,
            'The order screen should point at the link the signatory actually holds.',
        );
    }

    /**
     * A first send that never goes out must leave the order where it started:
     * no approval on the screen, so the operator sees "Надіслати на погодження"
     * and not a pending request nobody received.
     */
    public function test_a_first_send_that_never_goes_out_leaves_nothing_pending(): void
    {
        $order = $this->order();

        $notification = new OrderCreatedNotification($order);
        $notification->toMail($this->signatory);
        $notification->failed(new \RuntimeException('SMTP refused'));

        $this->assertSame(0, OrderApproval::where('order_id', $order->id)->count());
        $this->assertNull($order->fresh()->latestApproval);
    }

    /**
     * The brief's case: the job dies before toMail() ever runs, so there is no
     * row to undo. failed() must still record the failure and must not blow up
     * looking for one.
     */
    public function test_failing_before_the_message_is_built_is_recorded_and_harmless(): void
    {
        $order = $this->order();

        (new OrderCreatedNotification($order))->failed(new \RuntimeException('Queue lost it'));

        $this->assertSame(0, OrderApproval::count());
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'approval_email_failed']);
    }

    // ─── the route that sends it ─────────────────────────

    public function test_approval_cannot_be_requested_without_a_signatory_email(): void
    {
        $this->signatory->update(['email' => null]);
        $order = $this->order();

        $this->actingAs($this->operator)
            ->post(route('orders.send-approval', $order))
            ->assertSessionHas('error');

        $this->assertSame(0, OrderApproval::count());
    }

    // ─── the presenter: one description for the email, the Blade pages, and Telegram ────

    /**
     * A mixed order is grouped, in the categories' own order, with the cost of
     * each category stated — that is what the signatory is being asked about.
     */
    public function test_items_are_grouped_by_category_in_sort_order(): void
    {
        $binding = ServiceCategory::factory()->create(['name' => 'Палітурні роботи', 'sort_order' => 20]);
        $print = ServiceCategory::factory()->create(['name' => 'Чорно-білий друк', 'sort_order' => 10]);

        $bindingService = Service::factory()->create(['service_category_id' => $binding->id, 'type' => 'static']);
        $printService = Service::factory()->create(['service_category_id' => $print->id, 'type' => 'static']);

        $order = Order::factory()->create([
            'user_id'           => $this->operator->id,
            'type'              => 'internal',
            'authorized_person' => $this->signatory->full_name,
        ]);
        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_id'       => $bindingService->id,
            'service_name'     => 'Прошивка на пружину',
            'quantity'         => 3,
            'total_price_cost' => 30.00,
        ]);
        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_id'       => $printService->id,
            'service_name'     => 'Друк А4',
            'quantity'         => 25,
            'total_price_cost' => 42.50,
        ]);

        $groups = app(ApprovalItemsPresenter::class)->groupedByCategory($order);

        $this->assertCount(2, $groups);
        $this->assertSame('Чорно-білий друк', $groups[0]['category']);
        $this->assertSame('Палітурні роботи', $groups[1]['category']);
        $this->assertSame('Друк А4', $groups[0]['items'][0]['name']);
        $this->assertArrayNotHasKey('cost', $groups[0], 'Money is not the signatory\'s to see (owner, 2026-08-20)');
        $this->assertArrayNotHasKey('cost', $groups[1]);
    }

    /**
     * `service_categories.name` has no unique constraint, and the presenter
     * resolves categories `withTrashed()` on purpose. An admin who renames a
     * category by retiring the old row and creating a replacement with the same
     * name — an ordinary admin workflow — leaves two distinct categories that
     * happen to share a label. Grouped by name they would silently fold into
     * one, merging their costs; grouped by identity they stay apart.
     */
    public function test_two_categories_that_share_a_name_stay_separate(): void
    {
        $retired = ServiceCategory::factory()->create(['name' => 'Друк', 'sort_order' => 10]);
        $retiredService = Service::factory()->create(['service_category_id' => $retired->id, 'type' => 'static']);
        $retired->delete();

        $current = ServiceCategory::factory()->create(['name' => 'Друк', 'sort_order' => 20]);
        $currentService = Service::factory()->create(['service_category_id' => $current->id, 'type' => 'static']);

        $order = Order::factory()->create([
            'user_id'           => $this->operator->id,
            'type'              => 'internal',
            'authorized_person' => $this->signatory->full_name,
        ]);
        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_id'       => $retiredService->id,
            'service_name'     => 'Друк А4 (старий прайс)',
            'quantity'         => 10,
            'total_price_cost' => 20.00,
        ]);
        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_id'       => $currentService->id,
            'service_name'     => 'Друк А4',
            'quantity'         => 5,
            'total_price_cost' => 15.00,
        ]);

        $groups = app(ApprovalItemsPresenter::class)->groupedByCategory($order);

        $this->assertCount(2, $groups, 'Two distinct categories that share a name were folded into one.');
        $this->assertSame('Друк', $groups[0]['category']);
        $this->assertSame('Друк', $groups[1]['category']);
        $this->assertSame('Друк А4 (старий прайс)', $groups[0]['items'][0]['name']);
        $this->assertSame('Друк А4', $groups[1]['items'][0]['name']);
    }

    /**
     * A service retired or never categorised still has to appear — the signatory
     * is approving the work, not the reference book.
     */
    public function test_an_item_with_no_category_lands_under_inshe_and_last(): void
    {
        $print = ServiceCategory::factory()->create(['name' => 'Чорно-білий друк', 'sort_order' => 10]);
        $printService = Service::factory()->create(['service_category_id' => $print->id, 'type' => 'static']);

        $order = Order::factory()->create([
            'user_id'           => $this->operator->id,
            'type'              => 'internal',
            'authorized_person' => $this->signatory->full_name,
        ]);
        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_id'       => null,
            'service_name'     => 'Послуга без довідника',
            'quantity'         => 1,
            'total_price_cost' => 5.00,
        ]);
        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_id'       => $printService->id,
            'service_name'     => 'Друк А4',
            'quantity'         => 10,
            'total_price_cost' => 20.00,
        ]);

        $groups = app(ApprovalItemsPresenter::class)->groupedByCategory($order);

        $this->assertSame('Чорно-білий друк', $groups[0]['category']);
        $this->assertSame('Інше', $groups[1]['category']);
    }

    public function test_the_category_summary_names_every_category_once(): void
    {
        $print = ServiceCategory::factory()->create(['name' => 'Чорно-білий друк', 'sort_order' => 10]);
        $service = Service::factory()->create(['service_category_id' => $print->id, 'type' => 'static']);

        $order = Order::factory()->create([
            'user_id'           => $this->operator->id,
            'type'              => 'internal',
            'authorized_person' => $this->signatory->full_name,
        ]);
        OrderItem::factory()->count(2)->create([
            'order_id'   => $order->id,
            'service_id' => $service->id,
            'quantity'   => 1,
        ]);

        $this->assertSame(
            'Чорно-білий друк',
            app(ApprovalItemsPresenter::class)->categorySummary($order),
        );
    }

    /**
     * The rendered letter, not the array behind it: a signatory reading a mixed
     * order sees each category named, with what it costs.
     */
    public function test_the_rendered_letter_names_each_category_and_its_work(): void
    {
        $binding = ServiceCategory::factory()->create(['name' => 'Палітурні роботи', 'sort_order' => 20]);
        $print = ServiceCategory::factory()->create(['name' => 'Чорно-білий друк', 'sort_order' => 10]);

        $order = Order::factory()->create([
            'user_id'           => $this->operator->id,
            'type'              => 'internal',
            'authorized_person' => $this->signatory->full_name,
            'total_cost'        => 72.50,
        ]);
        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_id'       => Service::factory()->create(['service_category_id' => $print->id, 'type' => 'static'])->id,
            'service_name'     => 'Друк А4',
            'quantity'         => 25,
            'total_price_cost' => 42.50,
        ]);
        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_id'       => Service::factory()->create(['service_category_id' => $binding->id, 'type' => 'static'])->id,
            'service_name'     => 'Прошивка на пружину',
            'quantity'         => 3,
            'total_price_cost' => 30.00,
        ]);

        // The template itself, rendered from exactly what toMail() hands it —
        // every other case in this file reads `viewData`, so this is the same
        // data one layer further on.
        $html = view('emails.order-approval', $this->mail($order->load('items'))->viewData)->render();

        $this->assertStringContainsString('Чорно-білий друк', $html);
        $this->assertStringContainsString('Палітурні роботи', $html);
        $this->assertStringContainsString('Друк А4', $html);
        $this->assertStringContainsString('Прошивка на пружину', $html);

        // The preheader names the composition, so the message is legible in a list.
        $this->assertStringContainsString('Чорно-білий друк + Палітурні роботи', $html);
    }

    /**
     * Not one figure of money reaches the signatory — owner's decision,
     * 2026-08-20: what a job costs is CRM and accounting information. They
     * approve the composition and the print run, and are not answerable for
     * the price.
     *
     * Asserted on the rendered letter rather than on the view data, because
     * the data is only half the promise: a template can compose a figure of
     * its own from `$order`, which is what it did before this round.
     */
    public function test_the_letter_shows_the_signatory_no_money_at_all(): void
    {
        $print = ServiceCategory::factory()->create(['name' => 'Чорно-білий друк', 'sort_order' => 10]);
        $binding = ServiceCategory::factory()->create(['name' => 'Палітурні роботи', 'sort_order' => 20]);

        $order = Order::factory()->create([
            'user_id'           => $this->operator->id,
            'type'              => 'internal',
            'authorized_person' => $this->signatory->full_name,
            'total_cost'        => 72.50,
        ]);
        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_id'       => Service::factory()->create(['service_category_id' => $print->id, 'type' => 'static'])->id,
            'service_name'     => 'Друк А4',
            'quantity'         => 25,
            'total_price_cost' => 42.50,
        ]);
        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_id'       => Service::factory()->create(['service_category_id' => $binding->id, 'type' => 'static'])->id,
            'service_name'     => 'Прошивка на пружину',
            'quantity'         => 3,
            'total_price_cost' => 30.00,
        ]);

        $mail = $this->mail($order->load('items'));
        $html = view('emails.order-approval', $mail->viewData)->render();

        foreach (['грн', 'Собівартість', '72.50', '42.50', '30.00'] as $money) {
            $this->assertStringNotContainsString($money, $html, "The letter still shows «{$money}»");
            $this->assertStringNotContainsString($money, $mail->viewData['itemsSummary'], "The preheader still shows «{$money}»");
        }

        // The composition it *does* carry is still there — this is a guard
        // against money, not against the letter saying anything at all.
        $this->assertStringContainsString('Друк А4', $html);
        $this->assertStringContainsString('Прошивка на пружину', $html);
    }
}
