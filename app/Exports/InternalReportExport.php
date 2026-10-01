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
 * InternalReportExport — Internal orders grouped by cost center.
 * TZ §5: "Звіт «Внутрішні»"
 */
class InternalReportExport implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function __construct(
        private readonly Carbon $from,
        private readonly Carbon $to,
    ) {}

    public function collection()
    {
        return Order::with(['items', 'user'])
            ->where('type', OrderType::Internal->value)
            ->where('is_backdated', false)
            ->whereNotIn('status', [OrderStatus::Cancelled->value])
            ->whereBetween('created_at', [$this->from, $this->to])
            ->orderBy('cost_center')
            ->orderBy('created_at')
            ->get();
    }

    public function headings(): array
    {
        return [
            '№ Замовлення',
            'Дата',
            'Центр витрат',
            'Підписант',
            'Виконавець',
            'Послуги',
            'Собівартість, грн',
            'Комерційна ціна, грн',
            'Економія, грн',
            'Кліки Ч/Б',
            'Кліки Колір',
        ];
    }

    /**
     * @param Order $order
     */
    public function map($order): array
    {
        $items = $order->items;
        $cost = (float) $order->total_cost;
        $commercial = (float) $order->total_commercial;

        return [
            $order->order_number,
            $order->created_at->timezone('Europe/Kyiv')->format('d.m.Y H:i'),
            $order->cost_center ?? '—',
            $order->authorized_person ?? '—',
            $order->user?->name ?? '—',
            $items->pluck('service_name')->filter()->implode(', ') ?: '—',
            number_format($cost, 2, '.', ''),
            number_format($commercial, 2, '.', ''),
            number_format($commercial - $cost, 2, '.', ''),
            $items->sum('bw_clicks'),
            $items->sum('color_clicks'),
        ];
    }

    public function title(): string
    {
        return 'Внутрішні замовлення';
    }
}
