<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * ApprovalWorkflowTest — digital order approval via signed email links.
 *
 * ApprovalController had 0% coverage until 2026-07-27, which is why a
 * check-then-write race and a CSP-blocked submit guard both went unnoticed.
 * These tests pin the guarantees the flow is supposed to make:
 *
 * - a link works exactly once
 * - an unsigned or tampered link is refused
 * - expired, superseded and already-answered links do not act
 * - approving marks the paper request as received and leaves an audit trail
 */
class ApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(); // Telegram notifications must not reach the network

        $this->order = Order::factory()->create([
            'request_received' => false,
        ]);
    }

    private function approval(array $attributes = []): OrderApproval
    {
        return OrderApproval::create(array_merge([
            'order_id'        => $this->order->id,
            'token'           => bin2hex(random_bytes(24)),
            'signatory_email' => 'signatory@example.test',
            'signatory_name'  => 'Тестовий Підписант',
            'status'          => 'pending',
            'expires_at'      => now()->addHours(72),
        ], $attributes));
    }

    /**
     * `setUp()` builds a bare order; the pages under test are about what an
     * order is *made of*, so these cases give it two categories' worth of work.
     *
     * @param  array<int, array{category: ServiceCategory, name: string, quantity: int}>  $lines
     */
    private function itemsInCategories(array $lines): void
    {
        foreach ($lines as $line) {
            $service = Service::factory()->create([
                'service_category_id' => $line['category']->id,
                'type'                => 'static',
                'is_active'           => true,
            ]);

            OrderItem::factory()->create([
                'order_id'         => $this->order->id,
                'service_id'       => $service->id,
                'service_name'     => $line['name'],
                'quantity'         => $line['quantity'],
                'total_price_cost' => 10.00,
            ]);
        }
    }

    /**
     * The shape the system actually hands out — not a permanent signature.
     *
     * Both helpers used `URL::signedRoute()`, which never expires. Every
     * assertion below therefore ran against a link nothing in the application
     * mints, and the expiry test one screen down was green for that reason
     * alone: with a signature that outlives everything, the request reaches the
     * controller and the row's own check answers. The mailed link died with its
     * row, so in production the middleware answered first.
     */
    private function showUrl(OrderApproval $approval, ?string $action = null): string
    {
        return URL::temporarySignedRoute('approvals.show', $approval->linkValidUntil(), array_filter([
            'token'  => $approval->token,
            'action' => $action,
        ]));
    }

    private function respondUrl(OrderApproval $approval): string
    {
        return URL::temporarySignedRoute(
            'approvals.respond',
            $approval->linkValidUntil(),
            ['token' => $approval->token],
        );
    }

    // ─── Signature enforcement ───────────────────────────

    public function test_unsigned_link_is_refused(): void
    {
        $approval = $this->approval();

        $this->get(route('approvals.show', ['token' => $approval->token]))
            ->assertForbidden();
    }

    public function test_tampered_signature_is_refused(): void
    {
        $approval = $this->approval();

        $this->get($this->showUrl($approval).'x')->assertForbidden();
    }

    public function test_unknown_token_is_not_found(): void
    {
        $this->get(URL::signedRoute('approvals.show', ['token' => 'no-such-token']))
            ->assertNotFound();
    }

    // ─── Rendering ───────────────────────────────────────

    public function test_pending_link_renders_the_confirmation_page(): void
    {
        $approval = $this->approval();

        $this->get($this->showUrl($approval, 'approve'))
            ->assertOk()
            ->assertSee($this->order->order_number);
    }

    /**
     * The confirm page is the last thing the signatory reads before clicking.
     * A mixed order has to arrive there as a list under category headings, not
     * as one comma-joined run-on inside an info-row.
     */
    public function test_the_confirm_page_lists_the_items_under_their_categories(): void
    {
        $print = ServiceCategory::factory()->create(['name' => 'Чорно-білий друк', 'sort_order' => 10]);
        $binding = ServiceCategory::factory()->create(['name' => 'Палітурні роботи', 'sort_order' => 20]);

        $this->itemsInCategories([
            ['category' => $print, 'name' => 'Друк А4', 'quantity' => 25],
            ['category' => $binding, 'name' => 'Прошивка на пружину', 'quantity' => 3],
        ]);

        $this->get($this->showUrl($this->approval(), 'approve'))
            ->assertOk()
            ->assertSee('Чорно-білий друк')
            ->assertSee('Палітурні роботи')
            ->assertSee('Друк А4')
            ->assertSee('Прошивка на пружину');
    }

    /**
     * The reject page is where a signatory lands from the "reject" button in
     * the email — same requirement as the approve page: categories, not a
     * comma-joined run-on.
     */
    public function test_the_reject_page_lists_the_items_under_their_categories(): void
    {
        $print = ServiceCategory::factory()->create(['name' => 'Чорно-білий друк', 'sort_order' => 10]);
        $binding = ServiceCategory::factory()->create(['name' => 'Палітурні роботи', 'sort_order' => 20]);

        $this->itemsInCategories([
            ['category' => $print, 'name' => 'Друк А4', 'quantity' => 25],
            ['category' => $binding, 'name' => 'Прошивка на пружину', 'quantity' => 3],
        ]);

        $this->get($this->showUrl($this->approval(), 'reject'))
            ->assertOk()
            ->assertSee('Чорно-білий друк')
            ->assertSee('Палітурні роботи')
            ->assertSee('Друк А4')
            ->assertSee('Прошивка на пружину');
    }

    /**
     * The full page (no ?action=) is the fallback a bare mailed link resolves
     * to; it carries the same per-item list as the two confirm pages, just
     * inside its own `.items-list` markup rather than an info-row.
     */
    public function test_the_full_page_lists_the_items_under_their_categories(): void
    {
        $print = ServiceCategory::factory()->create(['name' => 'Чорно-білий друк', 'sort_order' => 10]);
        $binding = ServiceCategory::factory()->create(['name' => 'Палітурні роботи', 'sort_order' => 20]);

        $this->itemsInCategories([
            ['category' => $print, 'name' => 'Друк А4', 'quantity' => 25],
            ['category' => $binding, 'name' => 'Прошивка на пружину', 'quantity' => 3],
        ]);

        $this->get($this->showUrl($this->approval()))
            ->assertOk()
            ->assertSee('Чорно-білий друк')
            ->assertSee('Палітурні роботи')
            ->assertSee('Друк А4')
            ->assertSee('Прошивка на пружину');
    }

    public function test_inline_script_carries_a_csp_nonce(): void
    {
        // The submit guard is an inline <script>. SecurityHeaders sends
        // script-src 'self' 'nonce-...', so without a matching nonce the
        // browser drops it and double submission becomes possible.
        $response = $this->get($this->showUrl($this->approval(), 'approve'))->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('<script nonce="', $html);

        preg_match('/<script nonce="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m[1] ?? '', 'Inline script nonce must not be empty.');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("'nonce-{$m[1]}'", (string) $csp);
    }

    // ─── Approving ───────────────────────────────────────

    public function test_approving_marks_the_request_as_received(): void
    {
        $approval = $this->approval();

        $this->post($this->respondUrl($approval), ['action' => 'approve'])
            ->assertOk();

        $this->assertSame('approved', $approval->fresh()->status);
        $this->assertNotNull($approval->fresh()->responded_at);
        $this->assertTrue((bool) $this->order->fresh()->request_received);
    }

    public function test_approving_writes_an_audit_entry_without_a_user(): void
    {
        $this->post($this->respondUrl($this->approval()), ['action' => 'approve'])
            ->assertOk();

        $entry = AuditLog::where('event_type', 'order_approved_email')->first();

        $this->assertNotNull($entry, 'Approving via email must be audited.');
        $this->assertNull($entry->user_id, 'There is no CRM user behind an email approval.');
    }

    /** The operators' push says what was approved, not only that something was. */
    public function test_the_telegram_line_names_the_categories_approved(): void
    {
        // Left blank in .env.testing on purpose (nothing here should reach the
        // real network); TelegramService::deliver() no-ops without them, and
        // this is the one test in this file that reads the outgoing text —
        // same fix as watchTelegram() in InventoryDeductionTest.
        config([
            'services.telegram.bot_token' => 'test-token-123',
            'services.telegram.chat_id'   => '12345678',
        ]);

        $print = ServiceCategory::factory()->create(['name' => 'Чорно-білий друк', 'sort_order' => 10]);
        $binding = ServiceCategory::factory()->create(['name' => 'Палітурні роботи', 'sort_order' => 20]);

        $this->itemsInCategories([
            ['category' => $print, 'name' => 'Друк А4', 'quantity' => 25],
            ['category' => $binding, 'name' => 'Прошивка на пружину', 'quantity' => 3],
        ]);

        $this->post($this->respondUrl($this->approval()), ['action' => 'approve'])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Чорно-білий друк + Палітурні роботи'));
    }

    /**
     * End to end: a real click on «Погодити» in the letter puts the message in
     * both chats. The unit tests cover the fan-out in isolation; this one
     * covers that the controller actually goes through it.
     */
    public function test_an_approval_through_the_link_reaches_the_approvals_group(): void
    {
        config([
            'services.telegram.bot_token'         => 'test-token-123',
            'services.telegram.chat_id'           => '12345678',
            'services.telegram.approvals_chat_id' => '-1002233445566',
        ]);

        $this->post($this->respondUrl($this->approval()), ['action' => 'approve'])->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['chat_id'] === '12345678'
            && str_contains($request['text'] ?? '', 'Замовлення погоджено підписантом'));
        Http::assertSent(fn ($request) => $request['chat_id'] === '-1002233445566'
            && str_contains($request['text'] ?? '', 'Замовлення погоджено підписантом'));
    }

    // ─── Rejecting ───────────────────────────────────────

    public function test_rejecting_stores_the_reason(): void
    {
        $approval = $this->approval();

        $this->post($this->respondUrl($approval), [
            'action'           => 'reject',
            'rejection_reason' => 'Невірний тираж',
        ])->assertOk();

        $fresh = $approval->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Невірний тираж', $fresh->rejection_reason);
        $this->assertFalse((bool) $this->order->fresh()->request_received);
    }

    /**
     * `handleRejection()` carries the identical composition line as
     * `handleApproval()` — mirrored here rather than assumed. The brief places
     * it "before the reason", so this pins the order, not only the presence:
     * a future edit that swapped the two lines would still pass a
     * presence-only assertion.
     */
    public function test_the_telegram_rejection_line_names_the_categories_before_the_reason(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token-123',
            'services.telegram.chat_id'   => '12345678',
        ]);

        $print = ServiceCategory::factory()->create(['name' => 'Чорно-білий друк', 'sort_order' => 10]);
        $binding = ServiceCategory::factory()->create(['name' => 'Палітурні роботи', 'sort_order' => 20]);

        $this->itemsInCategories([
            ['category' => $print, 'name' => 'Друк А4', 'quantity' => 25],
            ['category' => $binding, 'name' => 'Прошивка на пружину', 'quantity' => 3],
        ]);

        $this->post($this->respondUrl($this->approval()), [
            'action'           => 'reject',
            'rejection_reason' => 'Невірний тираж',
        ])->assertOk();

        Http::assertSent(function ($request) {
            $text = $request['text'] ?? '';
            $composition = strpos($text, 'Чорно-білий друк + Палітурні роботи');
            $reason = strpos($text, 'Невірний тираж');

            return $composition !== false && $reason !== false && $composition < $reason;
        });
    }

    /**
     * The omission is a missing line, not a blank one — pinned on the wire,
     * not only in the ternary that produces it. `setUp()`'s order has no
     * items, so `categorySummary()` returns '' and no 🗂 line should exist
     * anywhere in the message actually sent to operators.
     */
    public function test_the_telegram_rejection_line_is_absent_for_an_itemless_order(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token-123',
            'services.telegram.chat_id'   => '12345678',
        ]);

        $this->post($this->respondUrl($this->approval()), [
            'action'           => 'reject',
            'rejection_reason' => 'Невірний тираж',
        ])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => ! str_contains($request['text'] ?? '', '🗂'));
    }

    /**
     * rejection_reason arrives on a public endpoint behind nothing but the
     * signed link (finding F-3, 2026-08-09 audit re-run). The column is
     * `text` and the Telegram line carries the reason verbatim, so anyone
     * with a live link could push megabytes into the database and a
     * guaranteed-rejected message into the operators' chat. Five hundred
     * characters is more reason than anyone has.
     */
    public function test_an_oversized_rejection_reason_is_refused(): void
    {
        $approval = $this->approval();

        $response = $this->post($this->respondUrl($approval), [
            'action'           => 'reject',
            'rejection_reason' => str_repeat('х', 501),
        ]);

        $response->assertSessionHasErrors('rejection_reason');
        $this->assertSame(
            'pending',
            $approval->fresh()->status,
            'An invalid request must not claim the one-time link.',
        );
    }

    public function test_an_unknown_action_is_rejected(): void
    {
        $approval = $this->approval();

        $this->post($this->respondUrl($approval), ['action' => 'maybe'])
            ->assertStatus(400);

        $this->assertSame('pending', $approval->fresh()->status);
    }

    // ─── One-time use ────────────────────────────────────

    public function test_a_link_cannot_be_used_twice(): void
    {
        $approval = $this->approval();
        $url = $this->respondUrl($approval);

        $this->post($url, ['action' => 'approve'])->assertOk();

        // Replay: the second call must not act again.
        $this->post($url, ['action' => 'approve'])
            ->assertOk()
            ->assertSee('Вже оброблено');

        $this->assertSame(
            1,
            AuditLog::where('event_type', 'order_approved_email')->count(),
            'A replayed link must not produce a second audit entry.',
        );
    }

    public function test_approving_cannot_be_flipped_to_rejected_afterwards(): void
    {
        $approval = $this->approval();
        $url = $this->respondUrl($approval);

        $this->post($url, ['action' => 'approve'])->assertOk();
        $this->post($url, ['action' => 'reject', 'rejection_reason' => 'передумав'])->assertOk();

        $this->assertSame('approved', $approval->fresh()->status);
        $this->assertNull($approval->fresh()->rejection_reason);
    }

    // ─── Non-actionable states ───────────────────────────

    public function test_expired_link_does_not_act(): void
    {
        $approval = $this->approval(['expires_at' => now()->subMinute()]);

        $this->post($this->respondUrl($approval), ['action' => 'approve'])->assertOk();

        $this->assertSame('pending', $approval->fresh()->status);
        $this->assertFalse((bool) $this->order->fresh()->request_received);
    }

    /**
     * The page written for this minute has to be the page that renders.
     *
     * `approvals/expired.blade.php` says «Посилання протухло» and what to do
     * next; it existed and had never been reached, because the mailed signature
     * expired at the same instant as the row and `signed` refuses before the
     * controller runs. What the signatory got was a bare 403 in English (audit
     * R33-1).
     */
    public function test_an_expired_link_explains_itself_rather_than_answering_403(): void
    {
        $approval = $this->approval(['expires_at' => now()->subMinute()]);

        $this->get($this->showUrl($approval, 'approve'))
            ->assertOk()
            ->assertSee('Посилання протухло');
    }

    /**
     * The grace window is a window, not the removal of the expiry.
     */
    public function test_a_link_past_the_grace_window_stops_verifying(): void
    {
        $approval = $this->approval([
            'expires_at' => now()->subDays(OrderApproval::LINK_GRACE_DAYS + 1),
        ]);

        $this->get($this->showUrl($approval, 'approve'))->assertForbidden();
    }

    /**
     * Nothing mints an approval link that never expires.
     *
     * The three pages built their forms with `URL::signedRoute()`, so the TTL
     * the class docblock advertises was gone the moment the signatory opened
     * the page: the signature outlived everything and only the row's own check
     * was left. A source assertion, because the defect is in what the code
     * calls, not in what one rendered page happens to contain.
     */
    public function test_no_approval_page_hands_out_a_link_that_never_expires(): void
    {
        $offenders = [];

        $files = array_merge(
            glob(resource_path('views/approvals/*.blade.php')) ?: [],
            [app_path('Notifications/OrderCreatedNotification.php')],
        );

        foreach ($files as $file) {
            if (str_contains((string) file_get_contents($file), 'URL::signedRoute(')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These build an approval URL with no expiry — use temporarySignedRoute($approval->linkValidUntil()).',
        );
    }

    public function test_superseded_link_does_not_act(): void
    {
        $approval = $this->approval(['status' => 'superseded']);

        $this->post($this->respondUrl($approval), ['action' => 'approve'])
            ->assertOk()
            ->assertSee('Посилання замінено');

        $this->assertSame('superseded', $approval->fresh()->status);
    }

    public function test_link_for_a_deleted_order_does_not_act(): void
    {
        $approval = $this->approval();
        $this->order->delete();

        $this->post($this->respondUrl($approval), ['action' => 'approve'])
            ->assertOk()
            ->assertSee('Замовлення видалено');

        $this->assertSame('pending', $approval->fresh()->status);
    }

    /**
     * A link lives for 72 hours; the order it approves can be cancelled well
     * inside that window. Deleted orders were already turned away, cancelled
     * ones were not (finding I-1, 2026-08-09 audit) — the click stamped
     * request_received on a dead order and told the operators it was agreed.
     */
    public function test_link_for_a_cancelled_order_does_not_act(): void
    {
        $approval = $this->approval();
        $this->order->forceFill(['status' => OrderStatus::Cancelled])->save();

        $this->post($this->respondUrl($approval), ['action' => 'approve'])
            ->assertOk()
            ->assertSee('Замовлення скасовано');

        $this->assertSame('pending', $approval->fresh()->status);
        $this->assertFalse((bool) $this->order->fresh()->request_received);
    }

    public function test_a_link_for_a_cancelled_order_explains_itself(): void
    {
        $approval = $this->approval();
        $this->order->forceFill(['status' => OrderStatus::Cancelled])->save();

        $this->get($this->showUrl($approval, 'approve'))
            ->assertOk()
            ->assertSee('Замовлення скасовано');
    }

    /**
     * None of the three pages a signatory can land on shows money — owner's
     * decision, 2026-08-20: what a job costs is CRM and accounting
     * information. They approve the composition and the print run.
     *
     * All three are covered because each renders its own list: fixing one and
     * forgetting another is exactly how a figure survives on the page nobody
     * re-opened.
     */
    public function test_no_page_the_signatory_lands_on_shows_money(): void
    {
        $print = ServiceCategory::factory()->create(['name' => 'Чорно-білий друк', 'sort_order' => 10]);
        $binding = ServiceCategory::factory()->create(['name' => 'Палітурні роботи', 'sort_order' => 20]);

        $this->itemsInCategories([
            ['category' => $print, 'name' => 'Друк А4', 'quantity' => 25],
            ['category' => $binding, 'name' => 'Прошивка на пружину', 'quantity' => 3],
        ]);
        $this->order->update(['total_cost' => 72.50]);

        $pages = [
            'confirm-approve' => $this->showUrl($this->approval(), 'approve'),
            'confirm-reject'  => $this->showUrl($this->approval(), 'reject'),
            'show'            => $this->showUrl($this->approval()),
        ];

        foreach ($pages as $page => $url) {
            $html = $this->get($url)->assertOk()->getContent();

            foreach (['грн', 'Собівартість', '72.50', '10.00', '20.00'] as $money) {
                $this->assertStringNotContainsString($money, $html, "«{$money}» still reaches the signatory on {$page}");
            }

            $this->assertStringContainsString('Друк А4', $html, "{$page} stopped naming the work as well");
        }
    }
}
