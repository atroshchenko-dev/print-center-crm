<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * TelegramServiceTest — verifies notification delivery and graceful degradation.
 */
class TelegramServiceTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_TOKEN = 'test-token-123';

    private const FAKE_CHAT = '12345678';

    private const FAKE_APPROVALS_CHAT = '-1002233445566';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.telegram.bot_token', self::FAKE_TOKEN);
        Config::set('services.telegram.chat_id', self::FAKE_CHAT);

        // The settings cache outlives a rolled-back transaction; without this a
        // test that switched the toggle off leaves the next one reading false.
        Cache::forget('app:settings');

        // Without this line the default depends on the environment. An empty
        // variable is the supported state «there is no group», and every other
        // test in this file must see exactly that.
        Config::set('services.telegram.approvals_chat_id', null);
    }

    /**
     * The rule, as a test — owner's decision 2026-07-31: an alert bypasses the
     * `telegram_enabled` toggle when it is the system reporting on itself, or an
     * irreversible act on money. Everything else an admin may switch off.
     *
     * Written down here as well as in the docblock, because a rule that only
     * lives in a comment is what the previous five rounds had, and it left every
     * new call site to be decided by eye.
     */
    public function test_the_toggle_silences_operational_traffic(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::setValue('telegram_enabled', false);

        $service = new TelegramService;

        $service->shiftOpened('Оператор', 1);
        $service->cashWithdrawal('Оператор', 500.00);
        $service->largeOrder('COM-2607-001', 1500.00, 'commercial');
        $service->lowStock('Папір А4', 5, 100);
        $service->orderEdited('INT-2607-001', 'Адмін', 50.00, 5_000.00);

        Http::assertNothingSent();
    }

    /**
     * Clause 2: an order and its money gone by an admin's hand. The only other
     * trace is the audit journal, which nobody reads daily — so with the toggle
     * off this used to be silent, while a 50 ₴ till discrepancy still pinged.
     */
    public function test_a_deleted_order_is_heard_through_a_closed_toggle(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::setValue('telegram_enabled', false);

        (new TelegramService)->orderDeleted('INT-2607-092', 4350.00, 'Адмін');

        Http::assertSent(fn ($request) => str_contains($request['text'], 'INT-2607-092')
            && str_contains($request['text'], number_format(4350.00, 2)));
    }

    /** Clause 1, and the owner's earlier decision of 2026-07-29. */
    public function test_a_cash_discrepancy_is_heard_through_a_closed_toggle(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::setValue('telegram_enabled', false);

        (new TelegramService)->cashDiscrepancy(142, 4350.00, 4300.00, null);

        Http::assertSent(fn ($request) => str_contains($request['text'], '142'));
    }

    /**
     * Where clause 2 stops. Money, but nothing irreversible: the till and the
     * order are both still there to look at. Sending these past the toggle
     * would leave it switching off almost nothing.
     */
    public function test_money_that_can_still_be_looked_at_stays_under_the_toggle(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::setValue('telegram_enabled', false);

        $service = new TelegramService;
        $service->cashWithdrawal('Оператор', 5000.00, 'Інкасація');
        $service->largeOrder('COM-2607-002', 9999.00, 'commercial');

        Http::assertNothingSent();
    }

    public function test_send_posts_to_telegram_api(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $service = new TelegramService;
        $service->send('Test message');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org/bot'.self::FAKE_TOKEN.'/sendMessage')
                && $request['chat_id'] === self::FAKE_CHAT
                && $request['text'] === 'Test message'
                && $request['parse_mode'] === 'Markdown';
        });
    }

    public function test_send_silently_skips_when_token_missing(): void
    {
        Config::set('services.telegram.bot_token', null);
        Http::fake();

        $service = new TelegramService;
        $service->send('Should not send');

        Http::assertNothingSent();
    }

    public function test_send_silently_skips_when_chat_id_missing(): void
    {
        Config::set('services.telegram.chat_id', null);
        Http::fake();

        $service = new TelegramService;
        $service->send('Should not send');

        Http::assertNothingSent();
    }

    public function test_send_logs_warning_on_http_failure(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response('Server Error', 500)]);
        Log::shouldReceive('warning')->once();

        // Force an exception by faking a throwing response
        Http::fake(function () {
            throw new \Exception('Connection timeout');
        });

        $service = new TelegramService;
        $service->send('Should fail gracefully');
    }

    // ─── A rejected message must not vanish in silence ───
    //
    // Http::post() does not throw on 4xx, so the catch above never fires for
    // an HTTP error: Telegram answers 400 to broken Markdown — an underscore
    // in a user-typed reason is enough — and to texts over 4096 characters,
    // and both vanished with no line in the log while monitoring greps for
    // exactly `TelegramService failed` (finding F-2, 2026-08-09 audit re-run).

    public function test_a_rejected_message_leaves_the_line_monitoring_greps(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok'          => false,
            'description' => "Bad Request: can't parse entities: Can't find end of the entity starting at byte offset 9",
        ], 400)]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message, $context = []) => $message === 'TelegramService failed'
                && ($context['status'] ?? null) === 400
                && str_contains((string) ($context['response'] ?? ''), "can't parse entities"));

        (new TelegramService)->send('Причина: не_той_формат');
    }

    public function test_a_rejected_critical_alert_is_not_lost_either(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok'          => false,
            'description' => 'Bad Request: message is too long',
        ], 400)]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message, $context = []) => $message === 'TelegramService failed'
                && ($context['status'] ?? null) === 400);

        (new TelegramService)->sendCritical(str_repeat('А', 5000));
    }

    /**
     * F-2 made the loss visible; the alert still has to arrive (finding
     * R3-3, third audit pass). The notification is the contract, the
     * formatting is not: when Telegram rejects the Markdown — a signatory's
     * underscore is enough — the same content goes out once more as plain
     * text. Bold is lost, the message arrives, and `TelegramService failed`
     * keeps meaning what monitoring takes it to mean: actually lost.
     */
    public function test_a_message_telegram_rejects_is_retried_as_plain_text(): void
    {
        Http::fake(fn ($request) => isset($request['parse_mode'])
            ? Http::response(['ok' => false, 'description' => "Bad Request: can't parse entities"], 400)
            : Http::response(['ok' => true]));

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($message) => $message === 'TelegramService fell back to plain text');
        Log::shouldReceive('warning')->never();

        (new TelegramService)->send('Причина: не_той_формат');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => ! isset($request['parse_mode'])
            && $request['text'] === 'Причина: не_той_формат');
    }

    public function test_a_delivered_message_logs_nothing(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        Log::shouldReceive('warning')->never();

        (new TelegramService)->send('Все добре');
    }

    public function test_shift_opened_formats_message_correctly(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $service = new TelegramService;
        $service->shiftOpened('Іван Петров', 42);

        Http::assertSent(function ($request) {
            $text = $request['text'];

            return str_contains($text, '🔓')
                && str_contains($text, '#42')
                && str_contains($text, 'Іван Петров');
        });
    }

    public function test_cash_withdrawal_includes_reason_when_provided(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $service = new TelegramService;
        $service->cashWithdrawal('Марія Коваль', 500.00, 'Придбання канцтоварів');

        Http::assertSent(function ($request) {
            $text = $request['text'];

            return str_contains($text, '💸')
                && str_contains($text, '500.00')
                && str_contains($text, 'Придбання канцтоварів');
        });
    }

    // ─── The large-order label on an edit ────────────────
    //
    // Owner's decision, 2026-08-04: the words go **inside** the edit message,
    // not into a second one. The money was never missing — round 18 made both
    // figures required — but «Велике замовлення» is what people type into the
    // chat search, and an order edited past the threshold could not be found
    // that way while one created past it could.

    private function editAndCaptureText(float $before, float $after): string
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        (new TelegramService)->orderEdited('INT-2608-007', 'Адмін', $before, $after);

        $sent = '';

        Http::recorded(function ($request) use (&$sent) {
            $sent = (string) ($request['text'] ?? '');

            return true;
        });

        return $sent;
    }

    public function test_an_edit_past_the_threshold_carries_the_words_people_search_for(): void
    {
        Setting::setValue('large_order_threshold', 500);

        $text = $this->editAndCaptureText(50.00, 5_000.00);

        $this->assertStringContainsString('Велике замовлення', $text);
        $this->assertStringContainsString('50.00', $text, 'Both figures stay — round 18.');
        $this->assertStringContainsString('5,000.00', $text);
    }

    public function test_an_edit_below_the_threshold_stays_a_plain_edit(): void
    {
        Setting::setValue('large_order_threshold', 500);

        $this->assertStringNotContainsString(
            'Велике замовлення',
            $this->editAndCaptureText(50.00, 120.00),
        );
    }

    /** The label is one message, never two — §1.1, and one step from R8-4. */
    public function test_the_label_does_not_add_a_second_message(): void
    {
        Setting::setValue('large_order_threshold', 500);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        (new TelegramService)->orderEdited('INT-2608-007', 'Адмін', 50.00, 5_000.00);

        $count = 0;

        Http::recorded(function ($request) use (&$count) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                $count++;
            }

            return true;
        });

        $this->assertSame(1, $count);
    }

    /**
     * Zero is how an admin switches the threshold off from Settings. It has to
     * mean «never», not «everything is large» — the trap §1.12 records for
     * `monthly_limit`, which defaults to 0 and would warn on every order.
     */
    public function test_a_threshold_of_zero_labels_nothing(): void
    {
        Setting::setValue('large_order_threshold', 0);

        $this->assertStringNotContainsString(
            'Велике замовлення',
            $this->editAndCaptureText(50.00, 5_000.00),
        );

        $this->assertFalse((new TelegramService)->isLargeOrder(1_000_000.00));
    }

    /** One rule, one place: the controller and the label ask the same function. */
    public function test_the_threshold_rule_answers_the_same_way_for_both_callers(): void
    {
        Setting::setValue('large_order_threshold', 500);

        $service = new TelegramService;

        $this->assertTrue($service->isLargeOrder(500.00), 'The threshold itself counts as large.');
        $this->assertFalse($service->isLargeOrder(499.99));
    }

    /**
     * When the forecast counted a convertible reserve into the days figure,
     * the line has to say so — «1 шт. (⏳ 2 дн.)» alone reads as a broken
     * calculation to anyone who knows one sheet does not last two days.
     */
    public function test_stock_forecast_names_the_reserve_behind_the_days(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        (new TelegramService)->stockForecast([
            [
                'name'         => 'Папір А4 200 г/м²',
                'current'      => 1.0,
                'daily_rate'   => 2.0,
                'days'         => 2,
                'reserve_qty'  => 2.0,
                'reserve_name' => 'Папір А3 200 г/м²',
            ],
        ]);

        Http::assertSent(fn ($request) => str_contains($request['text'], '*Папір А4 200 г/м²*: 1 шт. (+2 арк. «Папір А3 200 г/м²»)')
            && str_contains($request['text'], '⏳ 2 дн.'));
    }

    /** An item with no reserve keeps the plain line it always had. */
    public function test_stock_forecast_line_without_reserve_stays_plain(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        (new TelegramService)->stockForecast([
            ['name' => 'Тонер чорний', 'current' => 4.0, 'daily_rate' => 2.0, 'days' => 2],
        ]);

        Http::assertSent(fn ($request) => str_contains($request['text'], '*Тонер чорний*: 4 шт. (⏳ 2 дн.)')
            && ! str_contains($request['text'], 'арк. «'));
    }

    /**
     * The movement journal is not shown anywhere in the UI, so this message is
     * the only place an operator learns why the toner tab reads differently
     * than it did yesterday. Naming the line and both numbers is the point.
     */
    public function test_a_stocktake_summary_names_what_moved(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        (new TelegramService)->stocktakeCompleted([
            [
                'name'         => 'Тонер Konica Minolta bizhub 283',
                'full_before'  => 2.0,
                'full_after'   => 4.0,
                'empty_before' => 0.0,
                'empty_after'  => 1.0,
            ],
        ]);

        Http::assertSent(fn ($request) => str_contains($request['text'], 'Інвентаризація')
            && str_contains($request['text'], '*Тонер Konica Minolta bizhub 283*')
            && str_contains($request['text'], 'повні 2 → 4')
            && str_contains($request['text'], 'порожні 0 → 1'));
    }

    /** A count that moved nothing has nothing to report. */
    public function test_a_stocktake_that_changed_nothing_stays_quiet(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        (new TelegramService)->stocktakeCompleted([]);

        Http::assertNothingSent();
    }

    // ─── The second addressee: the approvals group ───
    //
    // The signatory's answers are the one event with two audiences: the
    // operators' chat, which gets them along with everything else, and the
    // narrow group opened for the electronic requests. No other notification
    // type reaches that group.

    public function test_an_approval_reaches_both_the_operators_and_the_group(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Config::set('services.telegram.approvals_chat_id', self::FAKE_APPROVALS_CHAT);

        $service = new TelegramService;
        $service->approvalApproved('INT-2608-072', 'Слечук Л.В.', 'Кольоровий друк');

        Http::assertSentCount(2);

        Http::assertSent(fn ($request) => $request['chat_id'] === self::FAKE_CHAT
            && str_contains($request['text'], 'Замовлення погоджено підписантом')
            && str_contains($request['text'], 'INT-2608-072')
            && str_contains($request['text'], 'Кольоровий друк'));

        Http::assertSent(fn ($request) => $request['chat_id'] === self::FAKE_APPROVALS_CHAT
            && str_contains($request['text'], 'Замовлення погоджено підписантом')
            && str_contains($request['text'], 'INT-2608-072')
            && str_contains($request['text'], 'Кольоровий друк'));
    }

    /** An empty variable is «no feature», not «half a feature». */
    public function test_without_a_group_the_approval_goes_out_exactly_once(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $service = new TelegramService;
        $service->approvalApproved('INT-2608-072', 'Слечук Л.В.', 'Кольоровий друк');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['chat_id'] === self::FAKE_CHAT);
    }

    /**
     * Owner's decision, 2026-08-25: one toggle governs both chats.
     *
     * That is the answer the rule on sendCritical() already gives — past the
     * toggle go only the system reporting on itself and an irreversible act on
     * money. An approval is neither, and a second addressee does not change it.
     */
    public function test_the_toggle_silences_the_group_too(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Config::set('services.telegram.approvals_chat_id', self::FAKE_APPROVALS_CHAT);
        Setting::setValue('telegram_enabled', false);

        $service = new TelegramService;
        $service->approvalApproved('INT-2608-072', 'Слечук Л.В.', 'Кольоровий друк');
        $service->approvalRejected('INT-2608-073', 'Слечук Л.В.', 'Чорно-білий друк', 'Немає бюджету');

        Http::assertNothingSent();
    }

    public function test_a_rejection_carries_the_reason_into_both_chats(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Config::set('services.telegram.approvals_chat_id', self::FAKE_APPROVALS_CHAT);

        $service = new TelegramService;
        $service->approvalRejected('INT-2608-073', 'Слечук Л.В.', 'Чорно-білий друк', 'Немає бюджету');

        Http::assertSentCount(2);

        Http::assertSent(fn ($request) => $request['chat_id'] === self::FAKE_CHAT
            && str_contains($request['text'], 'Замовлення відхилено підписантом')
            && str_contains($request['text'], 'Немає бюджету'));

        Http::assertSent(fn ($request) => $request['chat_id'] === self::FAKE_APPROVALS_CHAT
            && str_contains($request['text'], 'Замовлення відхилено підписантом')
            && str_contains($request['text'], 'Немає бюджету'));
    }

    /**
     * Kick the bot out of the group and the approval must still reach the
     * operators. Delivery order is the operators' chat first, the group second.
     */
    public function test_a_failed_group_delivery_does_not_take_the_operators_message_with_it(): void
    {
        Config::set('services.telegram.approvals_chat_id', self::FAKE_APPROVALS_CHAT);
        Log::shouldReceive('warning')->atLeast()->once();

        Http::fake(fn ($request) => $request['chat_id'] === self::FAKE_APPROVALS_CHAT
            ? Http::response(['ok' => false, 'description' => 'Forbidden: bot was kicked from the group chat'], 403)
            : Http::response(['ok' => true]));

        $service = new TelegramService;
        $service->approvalApproved('INT-2608-072', 'Слечук Л.В.', 'Кольоровий друк');

        Http::assertSent(fn ($request) => $request['chat_id'] === self::FAKE_CHAT
            && str_contains($request['text'], 'Замовлення погоджено підписантом'));
    }

    /**
     * `TelegramService failed` is the line monitoring greps. It has to name
     * the chat the delivery actually failed on: with two addressees, a log that
     * always names the operators' chat points at the live one while the group
     * is the dead one.
     */
    public function test_the_log_names_the_chat_the_delivery_failed_on(): void
    {
        Config::set('services.telegram.approvals_chat_id', self::FAKE_APPROVALS_CHAT);

        Http::fake(fn ($request) => $request['chat_id'] === self::FAKE_APPROVALS_CHAT
            ? Http::response(['ok' => false, 'description' => 'Forbidden'], 403)
            : Http::response(['ok' => true]));

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'TelegramService failed'
                && $context['chat_id'] === self::FAKE_APPROVALS_CHAT);

        $service = new TelegramService;
        $service->approvalApproved('INT-2608-072', 'Слечук Л.В.', 'Кольоровий друк');
    }
}
