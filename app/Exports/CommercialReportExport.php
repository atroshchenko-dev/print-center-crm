<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * CommercialReportExport — Commercial orders with payment breakdown.
 * TZ §5: "Звіт «Комерція»"
 */
class CommercialReportExport implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function __construct(
        private readonly Carbon $from,
        private readonly Carbon $to,
    ) {}

    public function collection()
    {
        // operational(), like the screen this file belongs to. Retro is an
        // internal-only module today, so the two queries agree in practice —
        // but they are obliged to agree by definition, and nothing was holding
        // them to it. Its sibling InternalReportExport spells the same
        // condition out as is_backdated = false.
        return Order::with(['items', 'user'])
            ->where('type', OrderType::Commercial->value)
            ->operational()
            ->whereNotIn('status', [OrderStatus::Cancelled->value])
            ->whereBetween('created_at', [$this->from, $this->to])
            ->orderBy('created_at')
            ->get();
    }

    public function headings(): array
    {
        return [
            '№ Замовлення',
            'Дата',
            'Виконавець',
            'Послуги',
            'Вартість, грн',
            'Собівартість, грн',
            'Маржа, грн',
            'Тип оплати',
            'По собівартості',
        ];
    }

    /**
     * @param  Order  $order
     */
    public function map($order): array
    {
        $payable = $order->getPayableAmount();
        $cost = (float) $order->total_cost;

        return [
            $order->order_number,
            $order->created_at->timezone('Europe/Kyiv')->format('d.m.Y H:i'),
            $order->user?->name ?? '—',
            $order->items->pluck('service_name')->filter()->implode(', ') ?: '—',
            number_format($payable, 2, '.', ''),
            number_format($cost, 2, '.', ''),
            number_format($payable - $cost, 2, '.', ''),
            $order->payment_method?->label() ?? '—',
            $order->is_at_cost ? 'Так' : '',
        ];
    }

    public function title(): string
    {
        return 'Комерційні замовлення';
    }
}
