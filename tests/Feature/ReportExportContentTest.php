<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LedgerTransactionType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Exports\CashFlowExport;
use App\Exports\CommercialReportExport;
use App\Exports\InternalReportExport;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The internal and commercial workbooks, against the screens they belong to.
 *
 * Both sat at ~3% coverage for ten rounds — collection() ran, map() and
 * headings() never did — and round 10 is what made them reachable from the
 * interface at all. Shipping a download button for a file nobody had executed
 * is the half-move this audit keeps writing up, so here is the file.
 *
 * The one real gap it found: the commercial workbook did not exclude retro
 * orders, although the screen above it does.
 */
class ReportExportContentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Оператор Тест']);
        $this->from = Carbon::parse('2026-07-01 00:00:00');
        $this->to = Carbon::parse('2026-07-31 23:59:59');
    }

    // ─── Internal ────────────────────────────────────────

    public function test_the_internal_sheet_prints_a_row_a_person_can_read(): void
    {
        $order = $this->order(OrderType::Internal, [
            'cost_center' => 'Кафедра А',
            'authorized_person' => 'Іваненко І.І.',
            'total_cost' => 40.00,
            'total_commercial' => 100.00,
        ], items: [['Чорно-білий друк', 40.00, 120, 0]]);

        $row = (new InternalReportExport($this->from, $this->to))->map($order);

        $this->assertSame($order->order_number, $row[0]);
        $this->assertSame('Кафедра А', $row[2]);
        $this->assertSame('Іваненко І.І.', $row[3]);
        $this->assertSame('Оператор Тест', $row[4]);
        $this->assertSame('Чорно-білий друк', $row[5]);
        $this->assertSame('40.00', $row[6]);
        $this->assertSame('100.00', $row[7]);
        $this->assertSame('60.00', $row[8], 'economy is commercial minus cost');
        $this->assertSame(120, $row[9]);
    }

    public function test_the_internal_sheet_dates_the_row_in_kyiv(): void
    {
        // 00:30 Kyiv on 6 July is 21:30 UTC on the 5th. The row has to name the
        // day the operator was working, not the UTC one — the same three-hour
        // window the 03:00 auto-close exists for.
        $order = $this->order(OrderType::Internal, [], createdAt: '2026-07-05 21:30:00');

        $row = (new InternalReportExport($this->from, $this->to))->map($order);

        $this->assertSame('06.07.2026 00:30', $row[1]);
    }

    public function test_the_internal_sheet_leaves_out_cancelled_and_retro_orders(): void
    {
        $kept = $this->order(OrderType::Internal);
        $this->order(OrderType::Internal, ['status' => OrderStatus::Cancelled->value]);
        $this->order(OrderType::Internal, ['is_backdated' => true]);

        $rows = (new InternalReportExport($this->from, $this->to))->collection();

        $this->assertCount(1, $rows);
        $this->assertSame($kept->id, $rows->first()->id);
    }

    public function test_the_internal_sheet_keeps_to_its_range(): void
    {
        $inside = $this->order(OrderType::Internal, createdAt: '2026-07-15 09:00:00');
        $this->order(OrderType::Internal, createdAt: '2026-06-15 09:00:00');

        $rows = (new InternalReportExport($this->from, $this->to))->collection();

        $this->assertCount(1, $rows);
        $this->assertSame($inside->id, $rows->first()->id);
    }

    // ─── Commercial ──────────────────────────────────────

    public function test_the_commercial_sheet_prints_a_row_a_person_can_read(): void
    {
        $order = $this->order(OrderType::Commercial, [
            'total_cost' => 40.00,
            'total_commercial' => 100.00,
            'payment_method' => PaymentMethod::Cash->value,
        ], items: [['Кольоровий друк', 100.00, 0, 60]]);

        $row = (new CommercialReportExport($this->from, $this->to))->map($order);

        $this->assertSame($order->order_number, $row[0]);
        $this->assertSame('Оператор Тест', $row[2]);
        $this->assertSame('Кольоровий друк', $row[3]);
        $this->assertSame('100.00', $row[4]);
        $this->assertSame('40.00', $row[5]);
        $this->assertSame('60.00', $row[6], 'margin is what was payable minus cost');
        // Was `cash` — pinned here as the enum's raw value while every screen in
        // the app writes «Готівка». Round 17: the accountant's file speaks the
        // same language as the screen it was exported from.
        $this->assertSame('Готівка', $row[7]);
    }

    public function test_the_commercial_sheet_bills_an_at_cost_order_at_cost(): void
    {
        $order = $this->order(OrderType::Commercial, [
            'total_cost' => 40.00,
            'total_commercial' => 100.00,
            'is_at_cost' => true,
        ]);

        $row = (new CommercialReportExport($this->from, $this->to))->map($order);

        $this->assertSame('40.00', $row[4], 'an at-cost order is payable at its cost');
        $this->assertSame('0.00', $row[6], 'so there is no margin on it');
        $this->assertSame('Так', $row[8]);
    }

    public function test_the_commercial_sheet_leaves_out_cancelled_and_retro_orders(): void
    {
        $kept = $this->order(OrderType::Commercial);
        $this->order(OrderType::Commercial, ['status' => OrderStatus::Cancelled->value]);

        // Retro is an internal-only module today, so a commercial order can
        // only reach this state from outside the interface. Kept anyway: the
        // screen this file belongs to applies operational() and the file did
        // not, and two queries obliged to agree were not agreeing. Same reason
        // R5-9 was fixed while unreachable.
        $this->order(OrderType::Commercial, ['is_backdated' => true]);

        $rows = (new CommercialReportExport($this->from, $this->to))->collection();

        $this->assertCount(1, $rows);
        $this->assertSame($kept->id, $rows->first()->id);
    }

    // ─── Cash flow ───────────────────────────────────────
    //
    // 23.5% for six rounds, and the only file of round 15's «worst four» that
    // nothing had touched since. `map()` had never run: the heuristic «defects
    // sit where the tests do not reach» is what produced R10-1 and R12-2, both
    // on exports, and it holds here too.
    //
    // The date window is not re-tested here — ReportShiftWindowTest already
    // pins it on this very class, with bounds built the way the controller
    // builds them. Two tests of one rule is the shape that produced R16-2.

    public function test_the_cash_flow_sheet_prints_a_row_a_person_can_read(): void
    {
        $shift = Shift::factory()->closed()->create(['date' => '2026-07-15', 'opened_by' => $this->admin->id]);

        $order = $this->order(OrderType::Commercial, [
            'shift_id' => $shift->id,
            'payment_method' => PaymentMethod::Card->value,
        ]);

        $tx = new LedgerTransaction([
            'shift_id' => $shift->id,
            'order_id' => $order->id,
            'type' => LedgerTransactionType::PaymentCard->value,
            'payment_method' => PaymentMethod::Card->value,
            'amount' => 250.00,
            'balance_after' => 1250.00,
            'comment' => 'Оплата карткою',
            'user_id' => $this->admin->id,
        ]);
        // Not through create(): `created_at` is outside $fillable, and the model
        // only defaults it when it is still null. Stored in UTC, like every
        // instant in this database — the sheet is what turns it into Kyiv.
        $tx->created_at = Carbon::parse('2026-07-15 09:30:45');
        $tx->save();

        $row = (new CashFlowExport($this->from, $this->to))->map($tx->fresh(['user', 'shift', 'order']));

        $this->assertSame($tx->id, $row[0]);
        $this->assertSame('15.07.2026 12:30:45', $row[1], 'the row is dated in Kyiv, the column is UTC');
        $this->assertSame('15.07.2026', $row[2]);
        $this->assertSame('Картка', $row[3], 'the transaction type, in Ukrainian');
        $this->assertSame($order->order_number, $row[4]);
        $this->assertSame('250.00', $row[5]);
        $this->assertSame('1250.00', $row[6]);
        $this->assertSame('Картка', $row[7], 'and the payment method too — it was `card`');
        $this->assertSame('Оплата карткою', $row[8]);
        $this->assertSame('Оператор Тест', $row[9]);
    }

    /**
     * A withdrawal or a shift-start line carries no payment method at all, and
     * an em dash is what the rest of these workbooks print for «nothing here».
     */
    public function test_a_transaction_with_no_payment_method_prints_a_dash(): void
    {
        $shift = Shift::factory()->closed()->create(['date' => '2026-07-16', 'opened_by' => $this->admin->id]);

        $tx = new LedgerTransaction([
            'shift_id' => $shift->id,
            'type' => LedgerTransactionType::Withdrawal->value,
            'amount' => -500.00,
            'balance_after' => 750.00,
            'user_id' => $this->admin->id,
        ]);
        $tx->created_at = Carbon::parse('2026-07-16 15:00:00');
        $tx->save();

        $row = (new CashFlowExport($this->from, $this->to))->map($tx->fresh(['user', 'shift', 'order']));

        $this->assertSame('Видача', $row[3]);
        $this->assertSame('—', $row[4], 'no order behind a withdrawal');
        $this->assertSame('—', $row[7]);
        $this->assertSame('', $row[8]);
    }

    public function test_the_cash_flow_sheet_has_a_heading_for_every_column_it_prints(): void
    {
        $export = new CashFlowExport($this->from, $this->to);

        $shift = Shift::factory()->closed()->create(['date' => '2026-07-15', 'opened_by' => $this->admin->id]);
        $tx = LedgerTransaction::create([
            'shift_id' => $shift->id,
            'type' => LedgerTransactionType::ShiftStart->value,
            'amount' => 0,
            'balance_after' => 0,
            'user_id' => $this->admin->id,
        ]);

        $this->assertCount(
            count($export->headings()),
            $export->map($tx->fresh(['user', 'shift', 'order'])),
        );
        $this->assertSame('Рух коштів', $export->title());
    }

    // ─── Helpers ─────────────────────────────────────────

    /**
     * @param  array<int, array{0: string, 1: float, 2: int, 3: int}>  $items
     */
    private function order(
        OrderType $type,
        array $attributes = [],
        string $createdAt = '2026-07-15 09:00:00',
        array $items = [],
    ): Order {
        $order = Order::factory()->create(array_merge([
            'type' => $type->value,
            'status' => OrderStatus::PaidIssued->value,
            'is_backdated' => false,
            'user_id' => $this->admin->id,
            'shift_id' => null,
            'total_cost' => 10.00,
            'total_commercial' => 20.00,
        ], $attributes));

        $order->forceFill(['created_at' => Carbon::parse($createdAt)])->saveQuietly();

        foreach ($items as [$service, $cost, $bw, $color]) {
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'service_name' => $service,
                'quantity' => 1,
                'total_price_cost' => $cost,
                'bw_clicks' => $bw,
                'color_clicks' => $color,
                'riso_clicks' => 0,
            ]);
        }

        return $order->fresh(['items', 'user']);
    }
}
