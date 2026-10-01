<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exports\BackdatedOrdersExport;
use App\Exports\ReconciliationExport;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithTitle;
use Tests\TestCase;

/**
 * Every item of an exported order has to appear on some sheet.
 *
 * Both accounting exports group whole orders by the service of their *first*
 * item, and the category sheet then keeps only the items whose service matches
 * that group. An order with two services was therefore filed under the first
 * one, and everything it bought from the second appeared on no sheet at all —
 * while the summary sheet still counted the whole order's cost and quantity
 * against the first category. The two halves of one file disagreed.
 *
 * The existing export tests only ever counted sheets, on orders with exactly
 * one item each, so nothing here was covered: collection() and map() never ran
 * under Excel::fake() either.
 */
class ExportItemCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Service $print;
    private Service $lamination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $category = ServiceCategory::factory()->create(['name' => 'Друк']);

        $this->print = Service::factory()->create([
            'name'                => 'Чорно-білий друк',
            'type'                => 'constructor',
            'service_category_id' => $category->id,
        ]);
        $this->lamination = Service::factory()->create([
            'name'                => 'Ламінування',
            'type'                => 'constructor',
            'service_category_id' => $category->id,
        ]);
    }

    // ─── Backdated orders export ─────────────────────────

    public function test_a_second_service_in_one_retro_order_still_reaches_a_sheet(): void
    {
        $this->makeOrder(backdated: true, items: [
            [$this->print, 100.0],
            [$this->lamination, 40.0],
        ]);

        $rows = $this->allDetailRows((new BackdatedOrdersExport())->sheets());

        $this->assertEqualsCanonicalizing(
            ['Чорно-білий друк', 'Ламінування'],
            $rows->pluck('item.service_name')->all(),
            'The lamination item of a two-service order appeared on no sheet.',
        );
    }

    public function test_the_retro_summary_files_each_service_under_its_own_category(): void
    {
        $this->makeOrder(backdated: true, items: [
            [$this->print, 100.0],
            [$this->lamination, 40.0],
        ]);

        $summary = $this->summaryRows((new BackdatedOrdersExport())->sheets());

        // Column 0 category, column 3 cost. 140 ₴ of work is 100 of print and
        // 40 of lamination, not 140 of print.
        $this->assertSame('100.00', $summary['Чорно-білий друк'][3]);
        $this->assertSame('40.00', $summary['Ламінування'][3]);
        $this->assertSame('140.00', $summary['РАЗОМ'][3]);
    }

    public function test_the_retro_summary_quantity_follows_the_items_not_the_order(): void
    {
        $this->makeOrder(backdated: true, items: [
            [$this->print, 100.0, 7],
            [$this->lamination, 40.0, 3],
        ]);

        $summary = $this->summaryRows((new BackdatedOrdersExport())->sheets());

        $this->assertSame(7, $summary['Чорно-білий друк'][2]);
        $this->assertSame(3, $summary['Ламінування'][2]);
        $this->assertSame(10, $summary['РАЗОМ'][2]);
    }

    public function test_one_order_spanning_two_services_is_still_one_order_in_the_grand_total(): void
    {
        $this->makeOrder(backdated: true, items: [
            [$this->print, 100.0],
            [$this->lamination, 40.0],
        ]);

        $summary = $this->summaryRows((new BackdatedOrdersExport())->sheets());

        // It touches both categories, so it is counted on both lines — but the
        // house total counts orders, and there is one.
        $this->assertSame(1, $summary['Чорно-білий друк'][1]);
        $this->assertSame(1, $summary['Ламінування'][1]);
        $this->assertSame(1, $summary['РАЗОМ'][1]);
    }

    public function test_a_single_service_order_is_reported_exactly_as_before(): void
    {
        $this->makeOrder(backdated: true, items: [[$this->print, 100.0, 5]]);

        $sheets  = (new BackdatedOrdersExport())->sheets();
        $summary = $this->summaryRows($sheets);

        $this->assertCount(2, $sheets, 'one category sheet plus the summary');
        $this->assertSame(1, $summary['Чорно-білий друк'][1]);
        $this->assertSame(5, $summary['Чорно-білий друк'][2]);
        $this->assertSame('100.00', $summary['Чорно-білий друк'][3]);
        $this->assertSame('100.00', $summary['РАЗОМ'][3]);
    }

    // ─── Reconciliation export ───────────────────────────

    public function test_a_second_service_in_one_internal_order_still_reaches_a_sheet(): void
    {
        $this->makeOrder(backdated: false, items: [
            [$this->print, 100.0],
            [$this->lamination, 40.0],
        ]);

        $rows = $this->allDetailRows((new ReconciliationExport())->sheets());

        $this->assertEqualsCanonicalizing(
            ['Чорно-білий друк', 'Ламінування'],
            $rows->pluck('item.service_name')->all(),
        );
    }

    public function test_the_reconciliation_summary_splits_cost_by_service(): void
    {
        $this->makeOrder(backdated: false, items: [
            [$this->print, 100.0],
            [$this->lamination, 40.0],
        ]);

        $summary = $this->summaryRows((new ReconciliationExport())->sheets());

        $this->assertSame('100.00', $summary['Чорно-білий друк'][3]);
        $this->assertSame('40.00', $summary['Ламінування'][3]);
        $this->assertSame('140.00', $summary['РАЗОМ'][3]);
    }

    // ─── Helpers ─────────────────────────────────────────

    /**
     * Every row every category sheet would print, sheets flattened together.
     *
     * @param  array<int, object>  $sheets
     */
    private function allDetailRows(array $sheets): Collection
    {
        $rows = collect();

        foreach ($sheets as $sheet) {
            if ($sheet instanceof WithTitle && $sheet->title() === 'Зведення') {
                continue;
            }
            /** @var FromCollection $sheet */
            foreach ($sheet->collection() as $row) {
                $rows->push($row);
            }
        }

        return $rows;
    }

    /**
     * The summary sheet keyed by category name.
     *
     * @param  array<int, object>  $sheets
     * @return array<string, array<int, mixed>>
     */
    private function summaryRows(array $sheets): array
    {
        foreach ($sheets as $sheet) {
            if ($sheet instanceof WithTitle && $sheet->title() === 'Зведення') {
                $out = [];
                foreach ($sheet->collection() as $row) {
                    $values = $row instanceof Collection ? $row->all() : (array) $row;
                    $out[$values[0]] = $values;
                }

                return $out;
            }
        }

        $this->fail('No summary sheet in the export.');
    }

    /**
     * @param  array<int, array{0: Service, 1: float, 2?: int}>  $items
     */
    private function makeOrder(bool $backdated, array $items, string $date = '2026-07-15'): Order
    {
        $total = array_sum(array_map(fn ($i) => $i[1], $items));

        $order = Order::factory()->create([
            'type'              => OrderType::Internal->value,
            'status'            => OrderStatus::CompletedIssued->value,
            'is_backdated'      => $backdated,
            'user_id'           => $this->admin->id,
            'shift_id'          => null,
            'total_cost'        => $total,
            'total_commercial'  => 0,
            'authorized_person' => 'Тест',
            'cost_center'       => 'БШК',
        ]);

        $order->forceFill(['created_at' => Carbon::parse($date)])->saveQuietly();

        foreach ($items as $i => [$service, $cost]) {
            OrderItem::factory()->create([
                'order_id'         => $order->id,
                'service_id'       => $service->id,
                'service_name'     => $service->name,
                'quantity'         => $items[$i][2] ?? 1,
                'total_price_cost' => $cost,
                'bw_clicks'        => 0,
                'color_clicks'     => 0,
                'riso_clicks'      => 0,
            ]);
        }

        return $order->fresh();
    }
}
