<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exports\Concerns\GroupsOrdersByService;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

// BackdatedOrdersCategorySheet & BackdatedOrdersSummarySheet are secondary classes
// inside BackdatedOrdersExport.php — not autoloadable via PSR-4, so require the file.
require_once __DIR__.'/BackdatedOrdersExport.php';

/**
 * ReconciliationExport — XLSX export of operational internal orders for month-end reconciliation.
 *
 * Reuses BackdatedOrdersCategorySheet and BackdatedOrdersSummarySheet
 * (they are generic by design), filtering only operational (non-backdated) internal orders.
 */
class ReconciliationExport implements WithMultipleSheets
{
    use GroupsOrdersByService;

    public function __construct(
        private readonly ?Carbon $from = null,
        private readonly ?Carbon $to = null,
        private readonly ?bool $reconciled = null,
        private readonly ?string $authorizedPerson = null,
        private readonly ?string $costCenter = null,
        private readonly ?string $requestReceived = null,
    ) {}

    public function sheets(): array
    {
        $grouped = $this->groupOrdersByService($this->loadOrders());

        $sheets = [];
        foreach ($grouped as $categoryName => $categoryOrders) {
            $sheets[] = new BackdatedOrdersCategorySheet($categoryName, $categoryOrders);
        }
        $sheets[] = new BackdatedOrdersSummarySheet($grouped);

        return $sheets;
    }

    private function loadOrders(): Collection
    {
        return Order::with(['items', 'user', 'reconciler'])
            // Same flag the mapper reads. Without it the attribute is null and
            // every row of this workbook calls a letter-approved request a
            // paper one — sending the accountant to a folder that has nothing.
            ->withEmailApprovalFlag()
            ->where('type', OrderType::Internal->value)
            ->operational()
            ->whereNotIn('status', [OrderStatus::Cancelled->value])
            ->when($this->from && $this->to, fn ($q) => $q->whereBetween('created_at', [$this->from, $this->to]))
            ->when($this->reconciled !== null, fn ($q) => $q->where('is_reconciled', $this->reconciled))
            ->when($this->authorizedPerson, fn ($q, $ap) => $q->where('authorized_person', $ap))
            ->when($this->costCenter, fn ($q, $cc) => $q->where('cost_center', $cc))
            ->filterRequestSource($this->requestReceived)
            ->orderBy('created_at')
            ->get();
    }
}
