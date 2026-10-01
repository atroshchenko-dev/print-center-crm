<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Services\TelegramService;
use Illuminate\Console\Command;

/**
 * SendDailySummary
 *
 * Sends a Telegram message with today's summary at end of day.
 */
class SendDailySummary extends Command
{
    protected $signature = 'telegram:daily-summary';
    protected $description = 'Send daily order summary to Telegram';

    public function __construct(
        private readonly TelegramService $telegram,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = today('Europe/Kyiv');

        // whereDate() compares the stored UTC date against a Kyiv one. An order
        // taken between midnight and 03:00 Kyiv — the shift runs until the
        // auto-close at 03:00 — carries the previous UTC date and fell out of
        // both days' summaries. Bound the Kyiv day and convert, the way
        // ParsesDateRange does for the reports.
        $dayStart = $today->copy()->startOfDay()->utc();
        $dayEnd   = $today->copy()->endOfDay()->utc();

        // operational(): a retro import backfills old paperwork with today's
        // created_at, and without this the day's report counted work that was
        // done months ago. The dashboard and analytics exclude it already.
        $orders = Order::whereBetween('created_at', [$dayStart, $dayEnd])
            ->operational()
            ->whereNot('status', OrderStatus::Cancelled->value)
            ->get();

        $total      = $orders->count();
        $revenue    = $orders->where('type', OrderType::Commercial->value)
            ->sum(fn ($order) => $order->getPayableAmount());
        $internal   = $orders->where('type', OrderType::Internal->value)->count();
        $commercial = $orders->where('type', OrderType::Commercial->value)->count();
        $cancelled  = Order::whereBetween('created_at', [$dayStart, $dayEnd])
            ->operational()
            ->where('status', OrderStatus::Cancelled->value)
            ->count();

        $text = "📊 *Щоденний звіт — {$today->format('d.m.Y')}*\n\n"
              . "📦 Замовлень: *{$total}*\n"
              . "🏢 Внутрішніх: {$internal}\n"
              . "💼 Комерційних: {$commercial}\n"
              . "❌ Скасовано: {$cancelled}\n"
              . "💰 Дохід: *" . number_format((float) $revenue, 2) . " ₴*";

        $this->telegram->send($text);

        $this->info("Daily summary sent: {$total} orders, {$revenue} UAH");

        return self::SUCCESS;
    }
}

