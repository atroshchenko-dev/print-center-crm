<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\AuditLog;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * AuditLogExport — System audit trail.
 * TZ §5: "Системний лог (Audit)"
 */
class AuditLogExport implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function __construct(
        private readonly Carbon $from,
        private readonly Carbon $to,
        private readonly ?string $eventType = null,
        private readonly int|string|null $userId = null,
    ) {}

    /**
     * The same four filters the screen offers.
     *
     * The user filter was missing: the page narrows the journal to one person,
     * and the workbook came back holding everybody. It stayed unnoticed because
     * no page linked to this export at all until round 10.
     */
    public function collection()
    {
        return AuditLog::with('user')
            ->whereBetween('created_at', [$this->from, $this->to])
            ->when($this->eventType, fn ($q, $t) => $q->where('event_type', $t))
            ->when($this->userId, fn ($q, $id) => $q->where('user_id', $id))
            ->latest('created_at')
            ->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Дата/Час',
            'Тип події',
            'Користувач',
            'Опис',
            'Об\'єкт ID',
        ];
    }

    /**
     * @param AuditLog $log
     */
    public function map($log): array
    {
        return [
            $log->id,
            $log->created_at->timezone('Europe/Kyiv')->format('d.m.Y H:i:s'),
            $log->event_type,
            $log->user?->name ?? 'Система',
            $log->description,
            $log->meta['order_id'] ?? '—',
        ];
    }

    public function title(): string
    {
        return 'Audit Trail';
    }
}
