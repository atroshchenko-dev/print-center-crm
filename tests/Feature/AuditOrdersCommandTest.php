<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RisoPriceTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `audit:orders` — the reviewer of past orders.
 *
 * Two things have to hold, and the second matters more than any single check:
 *
 *  - each check finds the fingerprint it exists for, and stays quiet on a clean
 *    order — a reviewer that cannot go quiet is C-1 wearing a new hat;
 *  - **the command writes nothing.** The audit never repriced a saved order,
 *    by migration or by command, and the tool built to look at them must not be
 *    the thing that starts.
 */
class AuditOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'is_backdated' => false,
        ], $attributes));
    }

    private function risoItem(Order $order, array $risoParams, int $quantity = 100): OrderItem
    {
        return OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_name'     => 'Тиражування',
            'quantity'         => $quantity,
            'service_snapshot' => [
                'service_type' => 'riso',
                'riso_params'  => $risoParams,
            ],
        ]);
    }

    // ─── The guarantee that outranks the checks ──────────

    public function test_the_command_writes_nothing(): void
    {
        $order = $this->order(['type' => 'commercial', 'order_number' => 'INT-2608-777']);
        $this->risoItem($order, ['format' => 'A4', 'sheets_a3' => 220, 'tier_label' => '50–99']);

        $before = [
            'orders'     => DB::table('orders')->count(),
            'items'      => DB::table('order_items')->count(),
            'movements'  => DB::table('inventory_movements')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
            'updated_at' => (string) $order->fresh()->updated_at,
            'number'     => $order->fresh()->order_number,
            'flag'       => (bool) $order->fresh()->limit_exceeded,
        ];

        $this->artisan('audit:orders')->assertSuccessful();

        $order->refresh();

        $this->assertSame($before['orders'], DB::table('orders')->count());
        $this->assertSame($before['items'], DB::table('order_items')->count());
        $this->assertSame($before['movements'], DB::table('inventory_movements')->count());
        $this->assertSame($before['audit_logs'], DB::table('audit_logs')->count(), 'The reviewer wrote to the audit log.');
        $this->assertSame($before['updated_at'], (string) $order->updated_at, 'An order was touched by a read.');
        $this->assertSame($before['number'], $order->order_number);
        $this->assertSame($before['flag'], (bool) $order->limit_exceeded);
    }

    // ─── Scope ───────────────────────────────────────────

    public function test_retro_orders_are_never_looked_at(): void
    {
        $retro = $this->order([
            'is_backdated' => true,
            'type'         => 'commercial',
            'order_number' => 'INT-2508-001',
        ]);

        $this->artisan('audit:orders --check=order-number')
            ->doesntExpectOutputToContain($retro->order_number)
            ->assertSuccessful();
    }

    public function test_the_window_excludes_orders_outside_it(): void
    {
        $old = $this->order([
            'type'         => 'commercial',
            'order_number' => 'INT-2601-001',
            'created_at'   => '2026-01-15 10:00:00',
        ]);

        $this->artisan('audit:orders --check=order-number --since=2026-07-01')
            ->doesntExpectOutputToContain($old->order_number)
            ->assertSuccessful();
    }

    // ─── R30-1: the snapshot argues with itself ──────────

    public function test_a_tier_label_that_does_not_contain_its_own_sheet_count_is_found(): void
    {
        $order = $this->order(['order_number' => 'INT-2607-501']);
        $this->risoItem($order, [
            'format'        => 'A3',
            'sheets_a3'     => 220,
            'tier_label'    => '50–99',
            'cost_per_copy' => 0.41,
        ], 220);

        $this->artisan('audit:orders --check=riso-tier')
            ->expectsOutputToContain('INT-2607-501')
            ->assertSuccessful();
    }

    public function test_a_tier_that_contains_its_sheet_count_is_left_alone(): void
    {
        $order = $this->order(['order_number' => 'INT-2607-502']);
        $this->risoItem($order, [
            'format'        => 'A3',
            'sheets_a3'     => 220,
            'tier_label'    => '200–249',
            'cost_per_copy' => 0.14,
        ], 220);

        $this->artisan('audit:orders --check=riso-tier')
            ->doesntExpectOutputToContain('INT-2607-502')
            ->assertSuccessful();
    }

    public function test_an_open_ended_top_tier_is_understood(): void
    {
        $order = $this->order(['order_number' => 'INT-2607-503']);
        $this->risoItem($order, [
            'format'     => 'A3',
            'sheets_a3'  => 900,
            'tier_label' => '501+',
        ], 900);

        $this->artisan('audit:orders --check=riso-tier')
            ->doesntExpectOutputToContain('INT-2607-503')
            ->assertSuccessful();
    }

    // ─── R18-1: the number and the type ──────────────────

    public function test_a_commercial_order_numbered_int_is_found(): void
    {
        $this->order(['type' => 'commercial', 'order_number' => 'INT-2608-010']);

        $this->artisan('audit:orders --check=order-number')
            ->expectsOutputToContain('INT-2608-010')
            ->assertSuccessful();
    }

    // ─── R14-1: exposure, stated as a question ───────────

    /**
     * A ladder of exactly these tiers and no others.
     *
     * The migrations seed ten real tiers, so a test that only *adds* rows gets
     * two tiers with `min_qty = 100` and `findForQuantity()` picks whichever —
     * which is R19-3 and §1.8 reproduced inside a test. Found by running it:
     * the case below priced 100 sheets at the seeded 0.0600 and reported
     * 6.00 ₴ where the test meant 23.00 ₴.
     *
     * @param  array<int, array{int, int|null, float}>  $tiers
     */
    private function ladder(array $tiers): void
    {
        RisoPriceTier::query()->forceDelete();

        foreach ($tiers as [$min, $max, $cost]) {
            RisoPriceTier::create(['min_qty' => $min, 'max_qty' => $max, 'cost_per_copy' => $cost]);
        }
    }

    /**
     * The question has to be asked in money, not in sheets.
     *
     * The first version printed «різниця в аркушах змінює тариф» without ever
     * asking a tier — and on production most of its twenty-one lines had the
     * same tier on both sides. A claim stronger than its check is the whole
     * subject of this audit.
     */
    public function test_an_a4_riso_item_without_originals_is_priced_both_ways(): void
    {
        $this->ladder([[50, 99, 0.41], [100, 149, 0.23], [150, null, 0.14]]);
        $order = $this->order(['order_number' => 'INT-2607-092']);

        // 198 copies A4: whole-run rounding gives 99 sheets at 0.41 = 40.59,
        // two originals give 100 sheets at 0.23 = 23.00.
        $this->risoItem($order, [
            'format'        => 'A4',
            'sheets_a3'     => 99,
            'tier_label'    => '50–99',
            'cost_per_copy' => 0.41,
            'sides'         => 1,
            'paper_cost_a3' => 0,
        ], 198);

        // Output captured rather than expected: all three figures land on one
        // line, and `expectsOutputToContain()` matches each substring against a
        // *separate* write, so only the first of them would ever be satisfied.
        Artisan::call('audit:orders', ['--check' => ['riso-originals']]);
        $output = Artisan::output();

        $this->assertStringContainsString('INT-2607-092', $output);
        $this->assertStringContainsString('99 арк. на 40.59 ₴', $output, 'The stored price is not stated.');
        $this->assertStringContainsString(
            'за 2 оригіналів: 100 арк., -17.59 ₴',
            $output,
            'The realistic floor — the smallest split that moves the money — is not priced.',
        );
        $this->assertStringContainsString('тариф «100–149»', $output);
    }

    /**
     * The absurd end of the arithmetic must not stand in for the answer.
     *
     * On production every single line reported its largest difference at
     * `k = copies` — one copy per original — filling the page with figures like
     * «+233 ₴» for a job nobody would run: a risograph burns a master per
     * original. Printing only that ceiling is the mirror image of the silent
     * cap it replaced.
     *
     * So both ends are stated, and the ceiling is labelled.
     */
    public function test_the_arithmetic_ceiling_is_separated_from_the_realistic_floor(): void
    {
        $this->ladder([[50, 99, 0.41], [100, 149, 0.23], [150, 299, 0.14], [300, null, 0.10]]);
        $order = $this->order(['order_number' => 'INT-2606-008']);

        // 200 copies A4, stored as 100 sheets. Splitting into 8 originals of 25
        // costs a handful of sheets; splitting into 200 of one copy each is
        // arithmetic, not a job.
        $this->risoItem($order, [
            'format'        => 'A4',
            'sheets_a3'     => 100,
            'tier_label'    => '100–149',
            'cost_per_copy' => 0.23,
            'sides'         => 1,
            'paper_cost_a3' => 0,
        ], 200);

        Artisan::call('audit:orders', ['--check' => ['riso-originals']]);
        $output = Artisan::output();

        $this->assertStringContainsString('Найменша можлива різниця — за 8 оригіналів', $output);
        $this->assertStringContainsString('Арифметична стеля — за 200 оригіналів', $output);
        $this->assertStringContainsString('операційно неправдоподібно', $output);
    }

    /**
     * A run today's ladder cannot price is not given an invented price.
     *
     * With only the 50–99 tier present, every k that would change the sheet
     * count lands in a hole. `RisoPriceTier::findForQuantity()` returns null
     * there on purpose since R30-1 — nobody decided what a 100-sheet run costs
     * when its tier is missing — and this check must not decide either.
     */
    public function test_a_run_todays_ladder_cannot_price_is_not_given_an_invented_number(): void
    {
        $this->ladder([[50, 99, 0.41]]);

        $order = $this->order(['order_number' => 'INT-2606-080']);
        $this->risoItem($order, [
            'format'        => 'A4',
            'sheets_a3'     => 99,
            'tier_label'    => '50–99',
            'cost_per_copy' => 0.41,
            'sides'         => 1,
            'paper_cost_a3' => 0,
        ], 198);

        $this->artisan('audit:orders --check=riso-originals')
            ->doesntExpectOutputToContain('INT-2606-080')
            ->assertSuccessful();
    }

    public function test_an_item_that_records_its_originals_is_not_a_question(): void
    {
        $order = $this->order(['order_number' => 'INT-2608-093']);
        $this->risoItem($order, [
            'format'     => 'A4',
            'originals'  => 2,
            'sheets_a3'  => 100,
            'tier_label' => '100–149',
        ], 198);

        $this->artisan('audit:orders --check=riso-originals')
            ->doesntExpectOutputToContain('INT-2608-093')
            ->assertSuccessful();
    }

    public function test_an_a3_run_cannot_be_a_question_because_nothing_halves(): void
    {
        $order = $this->order(['order_number' => 'INT-2607-094']);
        $this->risoItem($order, [
            'format'     => 'A3',
            'sheets_a3'  => 198,
            'tier_label' => '150–199',
        ], 198);

        $this->artisan('audit:orders --check=riso-originals')
            ->doesntExpectOutputToContain('INT-2607-094')
            ->assertSuccessful();
    }

    // ─── R6-9: a return valued by the wrong movement ─────

    public function test_returns_all_priced_at_one_cost_against_two_deductions_are_found(): void
    {
        $order = $this->order(['order_number' => 'INT-2607-300']);
        $item = InventoryItem::factory()->create();
        $user = User::factory()->create();

        foreach ([[-10, 4.00], [-10, 1.00]] as [$qty, $cost]) {
            InventoryMovement::create([
                'inventory_item_id' => $item->id,
                'type'              => 'auto_deduct',
                'quantity'          => $qty,
                'unit_cost'         => $cost,
                'total_cost'        => abs($qty) * $cost,
                'reference_type'    => Order::class,
                'reference_id'      => $order->id,
                'user_id'           => $user->id,
            ]);
        }

        foreach ([10, 10] as $qty) {
            InventoryMovement::create([
                'inventory_item_id' => $item->id,
                'type'              => 'return',
                'quantity'          => $qty,
                'unit_cost'         => 4.00,   // the first movement's price, for both
                'total_cost'        => $qty * 4.00,
                'reference_type'    => Order::class,
                'reference_id'      => $order->id,
                'user_id'           => $user->id,
            ]);
        }

        $this->artisan('audit:orders --check=return-price')
            ->expectsOutputToContain('INT-2607-300')
            ->assertSuccessful();
    }

    public function test_one_deduction_price_is_not_a_finding(): void
    {
        $order = $this->order(['order_number' => 'INT-2607-301']);
        $item = InventoryItem::factory()->create();
        $user = User::factory()->create();

        InventoryMovement::create([
            'inventory_item_id' => $item->id,
            'type'              => 'auto_deduct',
            'quantity'          => -10,
            'unit_cost'         => 4.00,
            'total_cost'        => 40.00,
            'reference_type'    => Order::class,
            'reference_id'      => $order->id,
            'user_id'           => $user->id,
        ]);

        InventoryMovement::create([
            'inventory_item_id' => $item->id,
            'type'              => 'return',
            'quantity'          => 10,
            'unit_cost'         => 4.00,
            'total_cost'        => 40.00,
            'reference_type'    => Order::class,
            'reference_id'      => $order->id,
            'user_id'           => $user->id,
        ]);

        $this->artisan('audit:orders --check=return-price')
            ->doesntExpectOutputToContain('INT-2607-301')
            ->assertSuccessful();
    }

    // ─── R17-2: an edit, not merely an update ────────────

    /**
     * The first version of this check compared `reconciled_at` with
     * `updated_at`, and on production it returned six orders — one of them
     * reconciled and «changed» in the same minute, because reconciling *is* an
     * update. Every status change afterwards tripped it too.
     *
     * A check labelled DECISIVE that returns candidates is the defect this
     * command exists to find, one level up.
     */
    public function test_an_order_touched_after_reconciliation_but_not_edited_is_not_a_finding(): void
    {
        $order = $this->order([
            'order_number'  => 'INT-2606-022',
            'is_reconciled' => true,
            'reconciled_at' => now()->subHour(),
        ]);

        // Anything at all can move updated_at — a hand-over, a payment.
        $order->forceFill(['updated_at' => now()])->saveQuietly();

        $this->artisan('audit:orders --check=reconciled-before-edit')
            ->doesntExpectOutputToContain('INT-2606-022')
            ->assertSuccessful();
    }

    public function test_an_order_edited_after_reconciliation_is_a_finding(): void
    {
        $order = $this->order([
            'order_number'  => 'INT-2606-023',
            'is_reconciled' => true,
            'reconciled_at' => now()->subHour(),
        ]);

        AuditLog::record(
            eventType: 'order_edited',
            user: null,
            description: "Order {$order->order_number} edited.",
            meta: ['order_id' => $order->id],
        );

        $this->artisan('audit:orders --check=reconciled-before-edit')
            ->expectsOutputToContain('INT-2606-023')
            ->assertSuccessful();
    }

    public function test_an_edit_that_happened_before_the_reconciliation_is_not_a_finding(): void
    {
        $order = $this->order([
            'order_number'  => 'INT-2606-024',
            'is_reconciled' => false,
        ]);

        // The edit first, then the reconciliation — the ordinary case, and the
        // one that must stay quiet.
        //
        // Two earlier attempts at this test were wrong about *whose clock*
        // stamps a journal entry. Moving the row afterwards is refused outright
        // (`audit_logs` is append-only, and PostgreSQL says so), and
        // `Carbon::setTestNow()` does not reach it either: `AuditLog` has
        // `$timestamps = false` and `created_at` comes from the database
        // (`useCurrent()`). The second version therefore passed locally and
        // failed in CI on whichever microsecond landed first — a test decided
        // by a race, which is no test at all.
        //
        // So the gap is made real instead of assumed.
        AuditLog::record(
            eventType: 'order_edited',
            user: null,
            description: 'edited before it was reconciled',
            meta: ['order_id' => $order->id],
        );

        $order->forceFill([
            'is_reconciled' => true,
            'reconciled_at' => now()->addHour(),
        ])->saveQuietly();

        $this->artisan('audit:orders --check=reconciled-before-edit')
            ->doesntExpectOutputToContain('INT-2606-024')
            ->assertSuccessful();
    }

    // ─── The listing, and an unknown name ────────────────

    public function test_the_listing_names_every_check_and_its_kind(): void
    {
        $this->artisan('audit:orders --list')
            ->expectsOutputToContain('riso-tier')
            ->expectsOutputToContain('DECISIVE')
            ->expectsOutputToContain('EXPOSURE')
            ->assertSuccessful();
    }

    public function test_an_unknown_check_name_fails_loudly_rather_than_running_everything(): void
    {
        $this->artisan('audit:orders --check=no-such-check')
            ->assertFailed();
    }

    public function test_a_clean_database_reports_nothing_decisive(): void
    {
        $this->artisan('audit:orders')
            ->expectsOutputToContain('Доведених розходжень: 0')
            ->assertSuccessful();
    }
}
