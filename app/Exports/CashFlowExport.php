<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\PaymentMethod;
use App\Models\LedgerTransaction;
use App\Support\KyivClock;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * CashFlowExport — Ledger transaction history (cash flow).
 * TZ §5: "Рух коштів (Каса)"
 */
class CashFlowExport implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function __construct(
        private readonly Carbon $from,
        private readonly Carbon $to,
    ) {}

    public function collection()
    {
        // Kyiv calendar days, same window as the page: the bounds are UTC
        // instants of Kyiv midnights, so the start read as the previous day
        // and the export carried the shift before the range.
        return LedgerTransaction::with(['user', 'shift', 'order'])
            ->whereHas('shift', fn ($q) => $q->whereBetween('date', [
                KyivClock::format($this->from),
                KyivClock::format($this->to),
            ]))
            ->orderBy('created_at')
            ->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Дата/Час',
            'Зміна',
            'Тип',
            'Замовлення',
            'Сума, грн',
            'Баланс після, грн',
            'Оплата',
            'Коментар',
            'Виконавець',
        ];
    }

    /**
     * @param  LedgerTransaction  $tx
     */
    public function map($tx): array
    {
        // `type` is cast to the enum on the model — there is no string branch.
        $typeLabel = $tx->type->label();

        return [
            $tx->id,
            $tx->created_at->timezone('Europe/Kyiv')->format('d.m.Y H:i:s'),
            $tx->shift?->date?->format('d.m.Y') ?? '—',
            $typeLabel,
            $tx->order?->order_number ?? '—',
            number_format((float) $tx->amount, 2, '.', ''),
            number_format((float) $tx->balance_after, 2, '.', ''),
            // Not cast on the model (see its docblock) — a raw 'cash'/'card'
            // string, so it is resolved here rather than read as an enum.
            PaymentMethod::tryFrom((string) $tx->payment_method)?->label() ?? '—',
            $tx->comment ?? '',
            $tx->user?->name ?? '—',
        ];
    }

    public function title(): string
    {
        return 'Рух коштів';
    }
}
