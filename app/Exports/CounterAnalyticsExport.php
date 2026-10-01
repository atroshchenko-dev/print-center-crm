<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\ShiftCounterReading;
use App\Support\KyivClock;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * CounterAnalyticsExport — Equipment counter readings analytics.
 * TZ §5: "Аналітика лічильників"
 */
class CounterAnalyticsExport implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function __construct(
        private readonly Carbon $from,
        private readonly Carbon $to,
    ) {}

    public function collection()
    {
        // Kyiv calendar days, same window as the page — see CashFlowExport.
        return ShiftCounterReading::with(['equipment', 'shift'])
            ->whereHas('shift', fn ($q) => $q->whereBetween('date', [
                KyivClock::format($this->from),
                KyivClock::format($this->to),
            ]))
            // `id` after `created_at`, the same tiebreak lastKnownValue() and
            // physicalDelta() use. created_at is timestamp(0), so an adjustment
            // and the reading that follows it can share a second — and the
            // export would then order that second differently from the report it
            // is meant to explain.
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function headings(): array
    {
        return [
            'Дата зміни',
            'Апарат',
            'Тип зчитування',
            'Значення лічильника',
        ];
    }

    /**
     * @param ShiftCounterReading $reading
     */
    public function map($reading): array
    {
        $typeLabels = [
            'morning'    => 'Ранковий',
            'evening'    => 'Вечірній',
            'adjustment' => 'Коригування',
        ];

        return [
            $reading->shift?->date?->format('d.m.Y') ?? '—',
            $reading->equipment?->name ?? '—',
            $typeLabels[$reading->reading_type] ?? $reading->reading_type,
            $reading->counter_value,
        ];
    }

    public function title(): string
    {
        return 'Аналітика лічильників';
    }
}
