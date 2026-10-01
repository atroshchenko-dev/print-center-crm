<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TelegramService
 *
 * Sends operational notifications to Telegram bot.
 * Used for shift events, large orders, cash withdrawals.
 *
 * Injectable via constructor DI — allows mocking in tests.
 */
class TelegramService
{
    private readonly ?string $token;

    private readonly ?string $chatId;

    private readonly ?string $approvalsChatId;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token');
        $this->chatId = config('services.telegram.chat_id');
        $this->approvalsChatId = config('services.telegram.approvals_chat_id');
    }

    /**
     * Send an operational notification — shifts, orders, cash, stock.
     *
     * Governed by the `telegram_enabled` setting: this is the noise an admin
     * is allowed to switch off.
     */
    public function send(string $text): void
    {
        // Check if Telegram notifications are enabled in system settings
        if (! Setting::getValue('telegram_enabled', true)) {
            return;
        }

        $this->deliver($text);
    }

    /**
     * Send past the operational toggle.
     *
     * ─── THE RULE (owner's decision, 2026-07-31) ────────────────────────────
     *
     * An alert bypasses `telegram_enabled` when it is either:
     *
     *   1. **the system reporting on itself** — backups, queue, cron, disk —
     *      where silence is indistinguishable from health; or
     *   2. **an irreversible act on money** — something that cannot be undone
     *      by looking at it later.
     *
     * Everything else is the ordinary traffic of operators doing their job, and
     * an admin is entitled to switch it off: shifts opening and closing, cash
     * withdrawals, large orders, paper conversions, stock warnings, edits.
     *
     * This lived in a docblock as "not for ordinary traffic" for five rounds,
     * which meant every new call site was decided by eye. It is a rule now, and
     * the point of a rule is that the next call site resolves without asking.
     *
     * Where each existing caller lands:
     *
     *   critical  CheckBackup (3 alerts)  — clause 1
     *   critical  cashDiscrepancy()       — clause 2 (owner's decision, 2026-07-29, R9-7)
     *   critical  orderDeleted()          — clause 2: an order and its money gone
     *                                       by an admin's hand, and the only other
     *                                       trace is the audit journal, which
     *                                       nobody reads daily
     *   normal    everything else
     *
     * Note where clause 2 stops: cashWithdrawal() and largeOrder() are money but
     * not irreversible — the till and the order are both still there to look at.
     * Sending those past the toggle would leave it switching off almost nothing.
     */
    public function sendCritical(string $text): void
    {
        $this->deliver($text);
    }

    /**
     * Send to the operators' chat and, when one is configured, to the
     * approvals group as well.
     *
     * Only the signatory's answers use this. The group was opened for the
     * electronic requests and nothing else: shifts, cash, stock and edits stay
     * where they were, or it becomes the second channel nobody reads.
     *
     * The toggle is asked once, here, and it governs both chats — owner's
     * decision, 2026-08-25. That is also the answer the rule on sendCritical()
     * already gives: an approval is neither the system reporting on itself nor
     * an irreversible act on money, so an admin may switch it off. The
     * destination is a separate axis from the toggle, and adding one must not
     * quietly promote a notification past it.
     *
     * The text is composed once by the caller and reaches both chats
     * identically, so a screenshot forwarded out of one always matches the
     * other.
     */
    private function sendToApprovalAudience(string $text): void
    {
        if (! Setting::getValue('telegram_enabled', true)) {
            return;
        }

        $this->deliver($text);

        if ($this->approvalsChatId) {
            $this->deliver($text, $this->approvalsChatId);
        }
    }

    private function deliver(string $text, ?string $chatId = null): void
    {
        // Resolved here rather than at the call site so that the guard below
        // and both log lines speak about the same chat. While there was one
        // addressee it made no difference; with two, a log that always names
        // the main chat would point at the live one whenever the group is the
        // one that died.
        $chatId ??= $this->chatId;

        if (! $this->token || ! $chatId) {
            return;
        }

        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";

        try {
            // Http::post() does not throw on 4xx, so the catch below never
            // fires for an HTTP error. Telegram answers 400 to broken Markdown
            // — an underscore in user-typed text is enough — and to texts over
            // 4096 characters; both used to vanish with no line in the log (F-2).
            $response = Http::post($url, [
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);

            if ($response->successful()) {
                return;
            }

            // The alert is the contract, the formatting is not: the same
            // content goes out once more as plain text — bold is lost, the
            // message arrives. Only when that fails too is the alert
            // actually gone, and `TelegramService failed` in the log keeps
            // meaning exactly that.
            $retry = Http::post($url, [
                'chat_id' => $chatId,
                'text'    => $text,
            ]);

            if ($retry->successful()) {
                Log::info('TelegramService fell back to plain text', [
                    'status'      => $response->status(),
                    'response'    => mb_substr($response->body(), 0, 300),
                    'text_length' => mb_strlen($text),
                ]);

                return;
            }

            Log::warning('TelegramService failed', [
                'status'       => $response->status(),
                'response'     => mb_substr($response->body(), 0, 300),
                'retry_status' => $retry->status(),
                'chat_id'      => $chatId,
                'text_length'  => mb_strlen($text),
            ]);
        } catch (\Throwable $e) {
            Log::warning('TelegramService failed', [
                'error'       => $e->getMessage(),
                'chat_id'     => $chatId,
                'text_length' => mb_strlen($text),
            ]);
        }
    }

    /**
     * Notify: shift opened.
     */
    public function shiftOpened(string $userName, int $shiftId): void
    {
        $this->send("🔓 *Зміна #{$shiftId} відкрита*\n👤 {$userName}\n🕐 ".now()->timezone('Europe/Kyiv')->format('H:i d.m'));
    }

    /**
     * Notify: shift closed.
     */
    public function shiftClosed(string $userName, int $shiftId): void
    {
        $this->send("🔒 *Зміна #{$shiftId} закрита*\n👤 {$userName}\n🕐 ".now()->timezone('Europe/Kyiv')->format('H:i d.m'));
    }

    /**
     * Notify: cash withdrawal.
     */
    public function cashWithdrawal(string $userName, float $amount, ?string $reason = null): void
    {
        $text = "💸 *Вилучення готівки*\n👤 {$userName}\n💰 ".number_format($amount, 2).' ₴';
        if ($reason) {
            $text .= "\n📝 {$reason}";
        }
        $text .= "\n🕐 ".now()->timezone('Europe/Kyiv')->format('H:i d.m');
        $this->send($text);
    }

    /**
     * Notify: large order created.
     */
    public function largeOrder(string $orderNumber, float $amount, string $type): void
    {
        $typeLabel = $type === 'commercial' ? '💼 Комерційний' : '🏢 Внутрішній';
        $this->send("📦 *Велике замовлення*\n🔢 {$orderNumber}\n{$typeLabel}\n💰 ".number_format($amount, 2)." ₴\n🕐 ".now()->timezone('Europe/Kyiv')->format('H:i d.m'));
    }

    /**
     * Notify: automatic paper conversion (A3 → A4 cutting).
     *
     * @param  string[]  $conversions  Human-readable conversion descriptions
     */
    public function paperConverted(string $orderNumber, array $conversions): void
    {
        $lines = implode("\n", $conversions);
        $this->send("✂️ *Авто-розрізка паперу*\n🔢 {$orderNumber}\n{$lines}\n🕐 ".now()->timezone('Europe/Kyiv')->format('H:i d.m'));
    }

    /**
     * Notify: significant cash discrepancy detected during shift settlement.
     *
     * Past the operational toggle, by the owner's decision of 2026-07-29. Every
     * other notification here reports what the system or its operators did on
     * purpose; this one reports that the money counted does not match the money
     * expected, over a threshold the admin sets. It is the closest thing this
     * system has to a signal about someone's actions rather than its own, and
     * whoever creates a discrepancy may be whoever can reach the settings.
     *
     * The audit entry in ShiftCloseService::reconcileCash() is written either
     * way — this governs only whether anyone is told at the time.
     */
    public function cashDiscrepancy(int $shiftId, float $expected, float $actual, ?string $reason): void
    {
        $diff = round($actual - $expected, 2);
        $sign = $diff > 0 ? '+' : '';
        $this->sendCritical(
            "⚠️ *Розбіжність каси*\n".
            "🔢 Зміна #{$shiftId}\n".
            '💰 Очікувано: '.number_format($expected, 2)." ₴\n".
            '💰 Фактично: '.number_format($actual, 2)." ₴\n".
            "📊 Різниця: {$sign}".number_format($diff, 2)." ₴\n".
            ($reason ? "📝 {$reason}\n" : '').
            '🕐 '.now()->timezone('Europe/Kyiv')->format('H:i d.m')
        );
    }

    /**
     * Notify: inventory item fell below minimum quantity threshold.
     */
    public function lowStock(string $itemName, float $currentQty, float $minQty): void
    {
        $this->send(
            "📦 *Низький залишок*\n".
            "🏷 {$itemName}\n".
            '📊 Залишок: '.number_format($currentQty, 0)." шт.\n".
            '⚠️ Мінімум: '.number_format($minQty, 0)." шт.\n".
            '🕐 '.now()->timezone('Europe/Kyiv')->format('H:i d.m')
        );
    }

    /**
     * Notify: proactive stock depletion forecast.
     *
     * @param  array<int, array{name: string, current: float, daily_rate: float, days: int, reserve_qty?: float, reserve_name?: string}>  $items
     */
    public function stockForecast(array $items): void
    {
        if (empty($items)) {
            return;
        }

        $lines = [];
        foreach ($items as $item) {
            $days = $item['days'] === 0 ? '⛔ сьогодні' : "⏳ {$item['days']} дн.";

            // The days figure may already count a convertible reserve (A3
            // sheets the deduction path will cut for this item); without
            // naming it, «1 шт. (⏳ 2 дн.)» reads as a broken calculation.
            $reserve = isset($item['reserve_qty'], $item['reserve_name'])
                ? ' (+'.number_format($item['reserve_qty'], 0)." арк. «{$item['reserve_name']}»)"
                : '';

            $lines[] = "  • *{$item['name']}*: ".number_format($item['current'], 0)." шт.{$reserve} ({$days})";
        }

        $this->send(
            "🔮 *Прогноз вичерпання запасів*\n\n".
            implode("\n", $lines)."\n\n".
            "📊 На основі витрат за 30 днів\n".
            '🕐 '.now()->timezone('Europe/Kyiv')->format('d.m.Y H:i')
        );
    }

    /**
     * Notify: a physical stocktake restated the balances.
     *
     * The movement journal is not shown anywhere in the UI — the procurement
     * forecast and the audit read it, no controller hands it to a page. Without
     * this message the operator opens the toner tab to different numbers and no
     * way to learn why they changed.
     *
     * Operational traffic: an admin may switch it off with the rest.
     *
     * @param  array<int, array{name: string, full_before: float, full_after: float, empty_before: float, empty_after: float}>  $changes
     */
    public function stocktakeCompleted(array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        $lines = [];
        foreach ($changes as $change) {
            $lines[] = "  • *{$change['name']}*: повні ".
                number_format($change['full_before'], 0).' → '.number_format($change['full_after'], 0).
                ', порожні '.number_format($change['empty_before'], 0).' → '.number_format($change['empty_after'], 0);
        }

        $this->send(
            "📋 *Інвентаризація тонерів*\n\n".
            implode("\n", $lines)."\n\n".
            '🕐 '.now()->timezone('Europe/Kyiv')->format('d.m.Y H:i')
        );
    }

    /**
     * Notify: order edited by operator.
     *
     * The amount is not optional, and the signature says so on purpose. Creation
     * pings `largeOrder()` with a figure whenever the order crosses the
     * threshold; an edit used to say «Замовлення відредаговано» and nothing
     * else, so an order taken at 50 ₴ and edited to 5 000 ₴ reached the chat as
     * the quieter of the two events. Both figures go out — what the edit
     * changed money-wise is the whole reason this line is read.
     */
    public function orderEdited(string $orderNumber, string $userName, float $amountBefore, float $amountAfter, ?string $reason = null): void
    {
        $text = "✏️ *Замовлення відредаговано*\n🔢 {$orderNumber}\n👤 {$userName}"
            ."\n💰 ".number_format($amountBefore, 2).' ₴ → '.number_format($amountAfter, 2).' ₴';

        // Owner's decision, 2026-08-04: the label goes **inside this message**,
        // not into a second one.
        //
        // The money was never missing — both figures are above, and have been
        // since round 18. What was missing is the words «Велике замовлення»,
        // which is what people type into the chat search. An order edited past
        // the threshold could not be found that way, while one created past it
        // could.
        //
        // A second message was the obvious alternative and was rejected: two
        // notifications for one action is what the rule on sendCritical() (§1.1)
        // was written to prevent, and it is one step from R8-4 — a channel
        // nobody reads.
        //
        // Marked by the **state** of the new amount, not by crossing. This is a
        // label on a one-off event, not a recurring monitor, so R34-1's rule
        // does not apply: a search for large orders should find every message
        // about an order that is large now.
        if ($this->isLargeOrder($amountAfter)) {
            $text .= "\n🔥 *Велике замовлення*";
        }

        if ($reason) {
            $text .= "\n📝 {$reason}";
        }
        $text .= "\n🕐 ".now()->timezone('Europe/Kyiv')->format('H:i d.m');
        $this->send($text);
    }

    /**
     * The large-order threshold, in one place.
     *
     * It lived as four lines inside `OrderController::store()` and nowhere else,
     * which was fine while exactly one caller asked the question. The moment a
     * second one did — the edit label above — the choice was to copy those four
     * lines or to move them here. This project has spent thirty-five rounds on
     * what the first option costs.
     *
     * `0` disables the threshold entirely, and that is deliberate: it is how an
     * admin switches the notification off from Settings without touching code.
     */
    public function isLargeOrder(float $amount): bool
    {
        $threshold = (float) Setting::getValue('large_order_threshold', 500);

        return $threshold > 0 && $amount >= $threshold;
    }

    /**
     * Notify: order soft-deleted by admin.
     *
     * Past the toggle, by clause 2 of the rule on sendCritical(): an order and
     * its money removed by an admin's hand is not something you can go and look
     * at afterwards. With notifications off it used to leave no signal at all
     * outside the audit journal, which nobody reads daily — so a 4 350 ₴ order
     * could disappear in silence while a 50 ₴ till discrepancy still pinged.
     */
    public function orderDeleted(string $orderNumber, float $amount, string $userName): void
    {
        $this->sendCritical(
            "🗑 *Замовлення видалено*\n".
            "🔢 {$orderNumber}\n".
            '💰 Сума: '.number_format($amount, 2)." ₴\n".
            "👤 {$userName}\n".
            '🕐 '.now()->timezone('Europe/Kyiv')->format('H:i d.m')
        );
    }

    /**
     * Notify: the signatory approved the order through the emailed link.
     *
     * The text lived inline in `ApprovalController` while every other
     * notification in this system was composed here. That was a style quibble
     * right up until the approvals group arrived: a second addressee decided in
     * the controller would have put the knowledge of which chats exist into the
     * one class with no business knowing it.
     *
     * @param  string  $composition  Category summary; an empty string prints no 🗂 line
     */
    public function approvalApproved(string $orderNumber, string $signatoryName, string $composition): void
    {
        $this->sendToApprovalAudience(
            "✅ *Замовлення погоджено підписантом*\n".
            "🔢 {$orderNumber}\n".
            "👤 {$signatoryName}\n".
            ($composition !== '' ? "🗂 {$composition}\n" : '').
            '🕐 '.now()->timezone('Europe/Kyiv')->format('H:i d.m')
        );
    }

    /**
     * Notify: the signatory rejected the order through the emailed link.
     *
     * @param  string  $composition  Category summary; an empty string prints no 🗂 line
     * @param  string|null  $reason  Rejection reason; null prints no 📝 line
     */
    public function approvalRejected(string $orderNumber, string $signatoryName, string $composition, ?string $reason): void
    {
        $this->sendToApprovalAudience(
            "❌ *Замовлення відхилено підписантом*\n".
            "🔢 {$orderNumber}\n".
            "👤 {$signatoryName}\n".
            ($composition !== '' ? "🗂 {$composition}\n" : '').
            ($reason ? "📝 {$reason}\n" : '').
            '🕐 '.now()->timezone('Europe/Kyiv')->format('H:i d.m')
        );
    }
}
