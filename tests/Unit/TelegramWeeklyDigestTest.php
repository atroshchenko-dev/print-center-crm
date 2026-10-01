<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\Shift;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * TelegramWeeklyDigestTest
 *
 * The digest window is a Kyiv week, but created_at is stored in UTC and a
 * Kyiv-zoned Carbon binds as its own wall clock. Without an explicit
 * conversion the window sat three hours late, so Monday's small hours — when
 * the shift is still running, before the 03:00 auto-close — were counted into
 * the week before.
 */
class TelegramWeeklyDigestTest extends TestCase
{
    use RefreshDatabase;

    private ?string $sent = null;
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = Shift::factory()->create();

        $this->mock(TelegramService::class, function ($mock) {
            $mock->shouldReceive('send')->andReturnUsing(function (string $text) {
                $this->sent = $text;
                return true;
            });
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function orderAt(string $kyivTime): void
    {
        Order::factory()->internal()->create([
            'created_at' => Carbon::parse($kyivTime, 'Europe/Kyiv')->utc(),
        ]);
    }

    private function runDigest(): void
    {
        // Monday morning, when the scheduler fires it.
        Carbon::setTestNow(Carbon::parse('2026-07-27 09:00:00', 'Europe/Kyiv'));
        $this->artisan('telegram:weekly-digest')->assertExitCode(0);
    }

    public function test_an_order_taken_in_mondays_small_hours_belongs_to_that_week(): void
    {
        // 01:00 Kyiv on Monday the 20th — the first day of the reported week,
        // still 22:00 UTC on Sunday the 19th.
        $this->orderAt('2026-07-20 01:00:00');

        $this->runDigest();

        $this->assertStringContainsString('Замовлень: *1*', $this->sent);
    }

    public function test_an_order_from_the_week_before_is_not_counted(): void
    {
        // 23:00 Kyiv on Sunday the 19th — one hour before the window opens.
        $this->orderAt('2026-07-19 23:00:00');

        $this->runDigest();

        $this->assertStringContainsString('Замовлень: *0*', $this->sent);
    }

    public function test_an_order_from_the_current_week_is_not_counted_yet(): void
    {
        // 01:00 Kyiv on Monday the 27th — this week, not the reported one.
        $this->orderAt('2026-07-27 01:00:00');

        $this->runDigest();

        $this->assertStringContainsString('Замовлень: *0*', $this->sent);
    }

    public function test_ordinary_midweek_orders_are_counted(): void
    {
        $this->orderAt('2026-07-22 14:00:00');
        $this->orderAt('2026-07-23 11:00:00');

        $this->runDigest();

        $this->assertStringContainsString('Замовлень: *2*', $this->sent);
    }

    public function test_the_label_names_the_reported_week_in_kyiv_dates(): void
    {
        $this->runDigest();

        $this->assertStringContainsString('20.07 — 26.07.2026', $this->sent);
    }

    // ─── What counts as the week's work ──────────────────────────────

    public function test_a_cancelled_order_is_not_part_of_the_week(): void
    {
        $this->orderAt('2026-07-22 14:00:00');
        Order::factory()->internal()->create([
            'status'     => OrderStatus::Cancelled->value,
            'created_at' => Carbon::parse('2026-07-22 15:00:00', 'Europe/Kyiv')->utc(),
        ]);

        $this->runDigest();

        $this->assertStringContainsString('Замовлень: *1*', $this->sent);
    }

    public function test_a_retro_import_is_not_part_of_the_week(): void
    {
        $this->orderAt('2026-07-22 14:00:00');
        Order::factory()->internal()->create([
            'is_backdated' => true,
            'created_at'   => Carbon::parse('2026-07-22 15:00:00', 'Europe/Kyiv')->utc(),
        ]);

        $this->runDigest();

        $this->assertStringContainsString('Замовлень: *1*', $this->sent);
    }

    // ─── Revenue ─────────────────────────────────────────────────────

    private function ledgerAt(string $kyivTime, string $type, ?string $method, float $amount): LedgerTransaction
    {
        Carbon::setTestNow(Carbon::parse($kyivTime, 'Europe/Kyiv')->utc());

        $tx = LedgerTransaction::create([
            'shift_id'       => $this->shift->id,
            'user_id'        => $this->shift->opened_by,
            'type'           => $type,
            'payment_method' => $method,
            'amount'         => $amount,
            'balance_after'  => 0,
        ]);

        Carbon::setTestNow();

        return $tx;
    }

    public function test_revenue_is_net_of_a_refund(): void
    {
        $this->ledgerAt('2026-07-22 12:00:00', 'payment_cash', 'cash', 500.00);
        $this->ledgerAt('2026-07-23 12:00:00', 'reversal', 'cash', -500.00);

        $this->runDigest();

        $this->assertStringContainsString('Виручка: *0.00 ₴*', $this->sent);
    }

    public function test_a_withdrawal_is_not_a_refund(): void
    {
        $this->ledgerAt('2026-07-22 12:00:00', 'payment_cash', 'cash', 500.00);
        // Taking cash out of the till moves money; it does not un-sell anything.
        $this->ledgerAt('2026-07-23 12:00:00', 'withdrawal', null, -200.00);

        $this->runDigest();

        $this->assertStringContainsString('Виручка: *500.00 ₴*', $this->sent);
    }
}
