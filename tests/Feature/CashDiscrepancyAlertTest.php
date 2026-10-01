<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftCloseService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Which side of the notification switch the cash discrepancy alert sits on.
 *
 * Round 8 split the channel in two: `send()` for the operational traffic an
 * admin may switch off, `sendCritical()` for what must always get through. The
 * discrepancy alert stayed on the operational side, and that was a product
 * question rather than a defect — the owner settled it on 2026-07-29: it goes
 * past the switch.
 *
 * The reasoning is worth pinning with the behaviour. Every other notification
 * reports something done on purpose. This one reports that the money counted
 * does not match the money expected, and the person who can silence it may be
 * the person it is about.
 */
class CashDiscrepancyAlertTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id'   => '123456',
        ]);

        $this->user = User::factory()->create(['role' => 'executor']);
    }

    /** A shift whose till should hold exactly 1000 ₴ and no ledger movement. */
    private function shift(): Shift
    {
        return Shift::factory()->create([
            'status'     => 'closed',
            'cash_start' => 1000,
        ]);
    }

    private function settle(Shift $shift, float $actual): void
    {
        app(ShiftCloseService::class)->reconcileCash(
            $shift,
            $this->user,
            $actual,
            'Перерахував двічі',
        );
    }

    public function test_a_discrepancy_over_the_threshold_is_reported_with_notifications_switched_off(): void
    {
        Setting::setValue('telegram_enabled', false);

        $this->settle($this->shift(), 500.0);   // 500 ₴ short, threshold 50

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Розбіжність каси'));
    }

    public function test_a_discrepancy_over_the_threshold_is_reported_with_notifications_on(): void
    {
        Setting::setValue('telegram_enabled', true);

        $this->settle($this->shift(), 500.0);

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Розбіжність каси'));
    }

    /**
     * The threshold still decides whether it is worth telling anyone. Moving the
     * alert past the toggle must not turn it into noise on every rounding.
     */
    public function test_a_discrepancy_under_the_threshold_is_not_reported(): void
    {
        Setting::setValue('cash_discrepancy_threshold', 50);

        $this->settle($this->shift(), 980.0);   // 20 ₴ short

        Http::assertNothingSent();
    }

    /**
     * The other half of the boundary, and the reason this test exists next to
     * the one above: the switch has to keep meaning something. An ordinary
     * operational notification stays silenced.
     */
    public function test_ordinary_notifications_still_obey_the_switch(): void
    {
        Setting::setValue('telegram_enabled', false);

        app(TelegramService::class)->shiftOpened('Оператор', 42);

        Http::assertNothingSent();
    }

    /**
     * Whatever the channel, the trail is written. The alert is a courtesy on
     * top of the audit entry, not a replacement for it.
     */
    public function test_the_audit_entry_is_written_either_way(): void
    {
        Setting::setValue('telegram_enabled', false);

        $shift = $this->shift();
        $this->settle($shift, 500.0);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'cash_discrepancy',
            'shift_id'   => $shift->id,
        ]);
    }
}
