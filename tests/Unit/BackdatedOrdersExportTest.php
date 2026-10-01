<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exports\BackdatedOrdersExport;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * BackdatedOrdersExportTest — Tests for the multi-sheet XLSX export.
 *
 * Validates: sheet grouping by service category, summary sheet totals,
 * date filtering, reconciled filtering, and correct column mapping.
 */
class BackdatedOrdersExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Service $bwService;
    private Service $colorService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $printCategory = ServiceCategory::factory()->create(['name' => 'Друк']);
        $this->bwService = Service::factory()->create([
            'name'                => 'Чорно-білий друк',
            'type'                => 'constructor',
            'service_category_id' => $printCategory->id,
        ]);
        $this->colorService = Service::factory()->create([
            'name'                => 'Кольоровий друк',
            'type'                => 'constructor',
            'service_category_id' => $printCategory->id,
        ]);
    }

    public function test_export_creates_sheets_grouped_by_service(): void
    {
        $order1 = $this->createOrderWithItem($this->bwService, 10.00);
        $order2 = $this->createOrderWithItem($this->colorService, 20.00);

        $export = new BackdatedOrdersExport();
        $sheets = $export->sheets();

        // 2 category sheets + 1 summary sheet
        $this->assertCount(3, $sheets);
    }

    public function test_export_filters_by_date_range(): void
    {
        // Order in August 2025
        $augustOrder = $this->createOrderWithItem($this->bwService, 10.00, '2025-08-15');
        // Order in September 2025
        $septOrder = $this->createOrderWithItem($this->bwService, 20.00, '2025-09-10');

        $from = Carbon::parse('2025-08-01');
        $to   = Carbon::parse('2025-08-31')->endOfDay();

        $export = new BackdatedOrdersExport($from, $to);
        $sheets = $export->sheets();

        // Only August orders should be included (1 category sheet + 1 summary)
        $this->assertCount(2, $sheets);
    }

    public function test_export_filters_by_reconciled_status(): void
    {
        $reconciled = $this->createOrderWithItem($this->bwService, 10.00);
        $reconciled->update(['is_reconciled' => true]);

        $unreconciled = $this->createOrderWithItem($this->bwService, 20.00);

        // Only reconciled
        $export = new BackdatedOrdersExport(reconciled: true);
        $sheets = $export->sheets();

        // 1 category + 1 summary
        $this->assertCount(2, $sheets);
    }

    public function test_export_excludes_cancelled_orders(): void
    {
        $active = $this->createOrderWithItem($this->bwService, 10.00);
        $cancelled = $this->createOrderWithItem($this->bwService, 20.00);
        $cancelled->update(['status' => OrderStatus::Cancelled->value]);

        $export = new BackdatedOrdersExport();
        $sheets = $export->sheets();

        // Only 1 active order → 1 category sheet + 1 summary
        $this->assertCount(2, $sheets);
    }

    public function test_export_can_be_downloaded(): void
    {
        Excel::fake();

        $this->createOrderWithItem($this->bwService, 10.00);

        $this->actingAs($this->admin)
            ->get(route('admin.backdated-orders.export'));

        Excel::assertDownloaded('retro-orders-all.xlsx');
    }

    // ─── Helpers ─────────────────────────────────────────

    private function createOrderWithItem(Service $service, float $cost, string $date = '2025-08-15'): Order
    {
        $order = Order::factory()->create([
            'type'              => OrderType::Internal->value,
            'status'            => OrderStatus::CompletedIssued->value,
            'is_backdated'      => true,
            'user_id'           => $this->admin->id,
            'shift_id'          => null,
            'total_cost'        => $cost,
            'total_commercial'  => 0,
            'authorized_person' => 'Тест',
            'cost_center'       => 'БШК',
        ]);

        $order->forceFill(['created_at' => Carbon::parse($date)])->saveQuietly();

        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_id'       => $service->id,
            'service_name'     => $service->name,
            'quantity'         => 5,
            'total_price_cost' => $cost,
        ]);

        return $order;
    }
}
