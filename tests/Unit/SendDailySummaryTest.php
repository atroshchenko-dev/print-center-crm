<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SendDailySummaryTest
 *
 * The one number the owner reads every evening, and the command had no test.
 * Two things decide it: which day the window covers — Kyiv, not UTC, because
 * the shift runs to the 03:00 auto-close — and which orders are the day's
 * trade. Retro imports are neither: they backfill old paperwork with today's
 * created_at, and every other screen leaves them out.
 */
class SendDailySummaryTest extends TestCase
{
    use RefreshDatabase;

    private ?string $sent = null;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function orderAt(string $kyivTime, array $attributes = []): void
    {
        Order::factory()->internal()->create(array_merge([
            'status'     => OrderStatus::New->value,
            'created_at' => Carbon::parse($kyivTime, 'Europe/Kyiv')->utc(),
        ], $attributes));
    }

    /** 18:00 Kyiv on 28 July — when the scheduler fires it. */
    private function runSummary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 18:00:00', 'Europe/Kyiv'));
        $this->artisan('telegram:daily-summary')->assertExitCode(0);
    }

    public function test_an_order_taken_after_midnight_kyiv_is_in_todays_report(): void
    {
        // 01:00 Kyiv on the 28th is 22:00 UTC on the 27th.
        $this->orderAt('2026-07-28 01:00:00');

        $this->runSummary();

        $this->assertStringContainsString('Замовлень: *1*', $this->sent);
    }

    public function test_yesterdays_order_is_not(): void
    {
        $this->orderAt('2026-07-27 23:00:00');

        $this->runSummary();

        $this->assertStringContainsString('Замовлень: *0*', $this->sent);
    }

    public function test_a_retro_import_does_not_inflate_the_day(): void
    {
        $this->orderAt('2026-07-28 10:00:00');
        $this->orderAt('2026-07-28 11:00:00', ['is_backdated' => true]);

        $this->runSummary();

        $this->assertStringContainsString('Замовлень: *1*', $this->sent);
    }

    public function test_a_cancelled_retro_import_is_not_counted_as_a_cancellation_either(): void
    {
        $this->orderAt('2026-07-28 11:00:00', [
            'is_backdated' => true,
            'status'       => OrderStatus::Cancelled->value,
        ]);

        $this->runSummary();

        $this->assertStringContainsString('Скасовано: 0', $this->sent);
    }

    public function test_a_cancelled_order_is_reported_separately_not_in_the_total(): void
    {
        $this->orderAt('2026-07-28 10:00:00');
        $this->orderAt('2026-07-28 11:00:00', ['status' => OrderStatus::Cancelled->value]);

        $this->runSummary();

        $this->assertStringContainsString('Замовлень: *1*', $this->sent);
        $this->assertStringContainsString('Скасовано: 1', $this->sent);
    }
}
