<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * PrunePersonalDataTest — the retention policy added for audit finding M-7.
 *
 * The point of the policy is a balance, so both halves need pinning: the
 * signatory's name, email and IP have to disappear on schedule, and the
 * approval record itself — status, timing, link to the order — has to survive,
 * because the financial trail depends on it.
 */
class PrunePersonalDataTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->order = Order::factory()->create();
    }

    private function approval(array $attributes = []): OrderApproval
    {
        return OrderApproval::create(array_merge([
            'order_id'        => $this->order->id,
            'token'           => bin2hex(random_bytes(24)),
            'signatory_email' => 'signer@example.edu',
            'signatory_name'  => 'Іван Петренко',
            'status'          => 'approved',
            'responded_at'    => now()->subDays(400),
            'responded_ip'    => '203.0.113.7',
            'expires_at'      => now()->subDays(397),
        ], $attributes));
    }

    public function test_signatory_data_older_than_the_window_is_removed(): void
    {
        $approval = $this->approval();

        $this->artisan('privacy:prune')->assertSuccessful();

        $approval->refresh();

        $placeholder = config('privacy.placeholder');
        $this->assertSame($placeholder, $approval->signatory_email);
        $this->assertSame($placeholder, $approval->signatory_name);
        $this->assertNull($approval->responded_ip);
        $this->assertNotNull($approval->anonymized_at);
        $this->assertTrue($approval->isAnonymized());
    }

    public function test_the_approval_record_itself_survives(): void
    {
        $approval = $this->approval(['status' => 'rejected', 'rejection_reason' => 'Не той тираж']);
        $respondedAt = $approval->responded_at;

        $this->artisan('privacy:prune')->assertSuccessful();

        $approval->refresh();

        $this->assertSame('rejected', $approval->status);
        $this->assertSame('Не той тираж', $approval->rejection_reason);
        $this->assertSame($this->order->id, $approval->order_id);
        $this->assertEquals($respondedAt->timestamp, $approval->responded_at->timestamp);
    }

    public function test_recent_approvals_are_left_alone(): void
    {
        $approval = $this->approval([
            'responded_at' => now()->subDays(30),
            'expires_at'   => now()->subDays(27),
        ]);

        $this->artisan('privacy:prune')->assertSuccessful();

        $approval->refresh();

        $this->assertSame('signer@example.edu', $approval->signatory_email);
        $this->assertNull($approval->anonymized_at);
    }

    /**
     * A link nobody ever opened has no responded_at, so the clock has to start
     * from expiry instead — otherwise the most sensitive rows (an email
     * address sitting next to a live-looking token) would never be cleaned.
     */
    public function test_links_that_were_never_answered_age_from_their_expiry(): void
    {
        $approval = $this->approval([
            'status'       => 'pending',
            'responded_at' => null,
            'responded_ip' => null,
            'expires_at'   => now()->subDays(400),
        ]);

        $this->artisan('privacy:prune')->assertSuccessful();

        $this->assertNotNull($approval->refresh()->anonymized_at);
    }

    public function test_a_pending_link_that_has_not_expired_yet_is_untouched(): void
    {
        $approval = $this->approval([
            'status'       => 'pending',
            'responded_at' => null,
            'expires_at'   => now()->addHours(72),
        ]);

        $this->artisan('privacy:prune')->assertSuccessful();

        $this->assertNull($approval->refresh()->anonymized_at);
        $this->assertSame('signer@example.edu', $approval->signatory_email);
    }

    public function test_dry_run_reports_without_changing_anything(): void
    {
        $approval = $this->approval();

        $this->artisan('privacy:prune --dry-run')->assertSuccessful();

        $approval->refresh();

        $this->assertSame('signer@example.edu', $approval->signatory_email);
        $this->assertNull($approval->anonymized_at);
        $this->assertSame(0, AuditLog::where('event_type', 'privacy_prune')->count());
    }

    public function test_running_twice_is_a_no_op_the_second_time(): void
    {
        $this->approval();

        $this->artisan('privacy:prune')->assertSuccessful();
        $this->artisan('privacy:prune')->assertSuccessful();

        // One prune actually did work; the second found nothing left to do.
        $this->assertSame(1, AuditLog::where('event_type', 'privacy_prune')->count());
    }

    public function test_the_audit_entry_records_the_count_but_no_personal_data(): void
    {
        $this->approval();
        $this->approval(['signatory_email' => 'signer2@example.edu']);

        $this->artisan('privacy:prune')->assertSuccessful();

        $entry = AuditLog::where('event_type', 'privacy_prune')->sole();

        $this->assertSame(2, $entry->meta['approvals']);
        $this->assertSame(365, $entry->meta['retention_days']);
        $this->assertStringNotContainsString('@', json_encode($entry->meta, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_window_can_be_overridden_per_run(): void
    {
        $approval = $this->approval([
            'responded_at' => now()->subDays(200),
            'expires_at'   => now()->subDays(197),
        ]);

        $this->artisan('privacy:prune')->assertSuccessful();
        $this->assertNull($approval->refresh()->anonymized_at, 'default window is 365 days');

        $this->artisan('privacy:prune --days=180')->assertSuccessful();
        $this->assertNotNull($approval->refresh()->anonymized_at);
    }

    public function test_a_zero_day_window_is_refused(): void
    {
        $this->artisan('privacy:prune --days=0')->assertFailed();
    }

    // ─── The customer's name on a business card ──────────

    /**
     * Owner's decision, 2026-07-31, closing a question open since round 2: the
     * name an operator types into `material_description` for a business-card
     * order is treated like an approval signatory's.
     */
    private function cardLine(int $orderAgeDays, string $description = 'Іваненко Іван Іванович'): OrderItem
    {
        $category = ServiceCategory::factory()->create([
            'name' => ServiceCategory::BUSINESS_CARDS,
        ]);
        $service = Service::factory()->create(['service_category_id' => $category->id]);

        $order = Order::factory()->create();
        Order::where('id', $order->id)->update(['created_at' => now()->subDays($orderAgeDays)]);

        return OrderItem::factory()->create([
            'order_id'             => $order->id,
            'service_id'           => $service->id,
            'material_description' => $description,
        ]);
    }

    public function test_a_customers_name_on_an_old_business_card_order_is_removed(): void
    {
        $line = $this->cardLine(400);

        $this->artisan('privacy:prune')->assertSuccessful();

        $this->assertSame(config('privacy.placeholder'), $line->refresh()->material_description);
    }

    /** The line, its quantity and its money stay. Only who it was for goes. */
    public function test_the_line_itself_survives(): void
    {
        $line = $this->cardLine(400);
        $quantity = $line->quantity;
        $cost = $line->total_price_cost;

        $this->artisan('privacy:prune')->assertSuccessful();

        $fresh = $line->refresh();
        $this->assertSame($quantity, $fresh->quantity);
        $this->assertSame($cost, $fresh->total_price_cost);
    }

    /**
     * The card an operator opens after the name is gone — audit item G16.
     *
     * This waited **on the calendar** for three months. The window is 365 days
     * and the oldest business card in production is `INT-2511-064` from
     * 03.11.2025, so «побачити готову картку» stood scheduled for 03.11.2026
     * and the list carried it as work in the meantime.
     *
     * It never needed the date. The placeholder is written by
     * `anonymizeDescription()` and read by `Orders/Show.vue`, and both can be
     * asked today — which is the round-26 move, «поставити те саме питання
     * тестом», applied to the last four items that were waiting on a calendar
     * rather than on us.
     *
     * What a test cannot answer is «did the scheduler run that night». That is
     * covered elsewhere: the heartbeat `/health` reads.
     */
    public function test_the_card_shows_the_placeholder_where_the_name_used_to_be(): void
    {
        $line = $this->cardLine(400);

        $this->artisan('privacy:prune')->assertSuccessful();

        $this->actingAs($this->admin())
            ->get(route('orders.show', $line->order_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.items.0.material_description', config('privacy.placeholder')));
    }

    /**
     * The same page inside the window still carries the name — so the check
     * above is reading the policy rather than an empty column.
     */
    public function test_the_card_still_shows_the_name_while_the_window_is_open(): void
    {
        $line = $this->cardLine(100);

        $this->artisan('privacy:prune')->assertSuccessful();

        $this->actingAs($this->admin())
            ->get(route('orders.show', $line->order_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.items.0.material_description', 'Іваненко Іван Іванович'));
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role'        => 'admin',
            'permissions' => User::permissionKeys(),
        ]);
    }

    public function test_a_recent_business_card_order_is_left_alone(): void
    {
        $line = $this->cardLine(100);

        $this->artisan('privacy:prune')->assertSuccessful();

        $this->assertSame('Іваненко Іван Іванович', $line->refresh()->material_description);
    }

    /**
     * The same field on other services holds order content — «Плакати»,
     * «Дипломи 2026, 3 курс» — and no personal data. Blanking it would destroy
     * history for no privacy gain, so the rule is scoped to «Візитівки».
     */
    public function test_a_description_on_another_service_is_not_touched(): void
    {
        $category = ServiceCategory::factory()->create(['name' => 'Друк']);
        $service = Service::factory()->create(['service_category_id' => $category->id]);

        $order = Order::factory()->create();
        Order::where('id', $order->id)->update(['created_at' => now()->subDays(400)]);

        $line = OrderItem::factory()->create([
            'order_id'             => $order->id,
            'service_id'           => $service->id,
            'material_description' => 'Дипломи 2026, 3 курс',
        ]);

        $this->artisan('privacy:prune')->assertSuccessful();

        $this->assertSame('Дипломи 2026, 3 курс', $line->refresh()->material_description);
    }

    /**
     * `order_items` has no `anonymized_at`, so the placeholder is the marker.
     * A second run must be a no-op rather than counting the same line again.
     */
    public function test_running_twice_changes_nothing_the_second_time(): void
    {
        $line = $this->cardLine(400);

        $this->artisan('privacy:prune')->assertSuccessful();
        $this->artisan('privacy:prune')->assertSuccessful();

        $this->assertSame(
            1,
            AuditLog::where('event_type', 'privacy_prune')->count(),
            'the second run had nothing to do and must not record a pass',
        );

        $this->assertSame(config('privacy.placeholder'), $line->refresh()->material_description);
    }

    public function test_the_dry_run_reports_business_card_lines_and_changes_nothing(): void
    {
        $line = $this->cardLine(400);

        $this->artisan('privacy:prune --dry-run')->assertSuccessful();

        $this->assertSame('Іваненко Іван Іванович', $line->refresh()->material_description);
    }

    // ─── What the rule hangs on, and what happens when it goes ─

    /*
     * The business-card rule reaches the line through the **live** service and
     * its category: `whereHas('service.category', … where name = 'Візитівки')`.
     * The command already guards one half of that chain — it warns when no
     * category by that name exists, and calls it «the L-6 hazard» in a comment.
     *
     * The other half was unguarded. `Service` is soft-deleted too, and a
     * `whereHas` honours the global scope, so retiring a service quietly drops
     * every line ever priced from it out of the sweep — while the category sits
     * there in place, keeping the warning silent. The order keeps the customer's
     * name past its retention window, and nothing anywhere says so.
     *
     * Same shape as R18-2, R19-1 and R19-2 — a rule that holds its subject by
     * something removable — but the currency here is not money.
     */

    public function test_retiring_a_service_does_not_strand_the_names_it_priced(): void
    {
        $line = $this->cardLine(400);

        $line->service->delete();

        $this->artisan('privacy:prune')->assertSuccessful();

        $this->assertSame(
            config('privacy.placeholder'),
            $line->refresh()->material_description,
            'the name was collected under the retention policy, not under the service',
        );
    }

    /** A retired **category** strands them the same way, and equally quietly. */
    public function test_retiring_the_category_does_not_strand_them_either(): void
    {
        $line = $this->cardLine(400);

        $line->service->category->delete();

        $this->artisan('privacy:prune')->assertSuccessful();

        $this->assertSame(config('privacy.placeholder'), $line->refresh()->material_description);
    }
}
