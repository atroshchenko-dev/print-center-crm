<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LedgerTransactionType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\InventoryMovement;
use App\Models\LedgerTransaction;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sends a weekly business digest to Telegram every Monday at 09:00.
 *
 * Covers:
 * - Total orders (commercial + internal)
 * - Revenue (cash + card)
 * - Inventory auto-conversions
 * - Top departments by print volume
 */
class TelegramWeeklyDigest extends Command
{
    protected $signature = 'telegram:weekly-digest';
    protected $description = 'Send weekly business metrics digest to Telegram';

    public function handle(TelegramService $telegram): int
    {
        $weekStartKyiv = Carbon::now('Europe/Kyiv')->subWeek()->startOfWeek();
        $weekEndKyiv   = Carbon::now('Europe/Kyiv')->subWeek()->endOfWeek();

        $label = $weekStartKyiv->format('d.m') . ' — ' . $weekEndKyiv->format('d.m.Y');

        // created_at is stored in UTC, and a Kyiv-zoned Carbon binds as its own
        // wall clock — so these bounds were three hours out and the digest
        // counted Monday's small hours into the week before. ParsesDateRange
        // and the daily summary already convert; this one never did.
        $weekStart = $weekStartKyiv->copy()->utc();
        $weekEnd   = $weekEndKyiv->copy()->utc();

        // Orders. Cancelled work was never done and retro imports are not this
        // week's trade — the daily summary and the dashboard both leave them
        // out, and a week that disagrees with seven days is worse than no week.
        $makeOrders = fn () => Order::whereBetween('created_at', [$weekStart, $weekEnd])
            ->operational()
            ->whereNot('status', OrderStatus::Cancelled->value);
        $totalOrders = $makeOrders()->count();
        $commercialOrders = $makeOrders()->where('type', OrderType::Commercial->value)->count();
        $internalOrders = $makeOrders()->where('type', OrderType::Internal->value)->count();

        // Revenue (from ledger), net of refunds. A reversal carries the payment
        // method it undoes and a negative amount; without it the digest kept
        // reporting money that had already been handed back.
        $totalRevenue = (float) LedgerTransaction::whereBetween('created_at', [$weekStart, $weekEnd])
            ->where(function ($q) {
                $q->whereIn('type', [
                    LedgerTransactionType::PaymentCash->value,
                    LedgerTransactionType::PaymentCard->value,
                ])->orWhere(function ($reversal) {
                    // payment_method is null on withdrawals, so this is exactly
                    // the reversals of payments.
                    $reversal->where('type', LedgerTransactionType::Reversal->value)
                        ->whereNotNull('payment_method');
                });
            })
            ->sum('amount');

        // Auto-conversions
        $conversions = InventoryMovement::whereBetween('created_at', [$weekStart, $weekEnd])
            ->where('type', 'conversion_in')
            ->count();

        // Top departments (by bw_clicks)
        $topDepts = $makeOrders()
            ->where('type', OrderType::Internal->value)
            ->whereNotNull('cost_center')
            ->selectRaw('cost_center, COUNT(*) as cnt')
            ->groupBy('cost_center')
            ->orderByDesc('cnt')
            ->limit(5)
            ->get();

        $deptLines = $topDepts->map(fn ($d) => "  • {$d->cost_center}: {$d->cnt} зам.")->implode("\n");

        $text = "📊 *Тижневий звіт CRM Print*\n"
            . "📅 {$label}\n\n"
            . "📦 Замовлень: *{$totalOrders}*\n"
            . "  💼 Комерційних: {$commercialOrders}\n"
            . "  🏢 Внутрішніх: {$internalOrders}\n\n"
            . "💰 Виручка: *" . number_format($totalRevenue, 2) . " ₴*\n"
            . "✂️ Авто-розрізок: {$conversions}\n";

        if ($deptLines) {
            $text .= "\n🏛 Топ відділи:\n{$deptLines}\n";
        }

        $telegram->send($text);

        $this->info("Weekly digest sent for {$label}");

        return self::SUCCESS;
    }
}
