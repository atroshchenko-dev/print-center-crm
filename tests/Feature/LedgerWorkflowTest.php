<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentMethod;
use App\Models\Equipment;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LedgerWorkflowTest — Feature tests for the cash register (ledger).
 * Covers TZ §4.3 + §6: append-only, cash vs card, withdrawals, reversals.
 */
class LedgerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $executor;
    private Shift $shift;
    private LedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = User::factory()->create(['role' => 'executor']);
        $equipment      = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);

        $shiftService = app(ShiftService::class);
        $this->shift  = $shiftService->openShift($this->executor, [
            ['equipment_id' => $equipment->id, 'counter_value' => 10000],
        ]);

        $this->ledgerService = app(LedgerService::class);
    }

    public function test_cash_payment_increases_balance(): void
    {
        $order = $this->createTestOrder();

        $tx = $this->ledgerService->recordPayment(
            $this->shift, $order, PaymentMethod::Cash, 200.00, $this->executor
        );

        $this->assertEquals(LedgerTransactionType::PaymentCash, $tx->type);
        $this->assertEquals(200.00, (float) $tx->amount);

        // Balance should be cash_start (0) + 200
        $balance = $this->ledgerService->getCurrentBalance($this->shift);
        $this->assertEquals(200.00, $balance);
    }

    public function test_card_payment_does_not_increase_cash_balance(): void
    {
        $order = $this->createTestOrder();

        $tx = $this->ledgerService->recordPayment(
            $this->shift, $order, PaymentMethod::Card, 300.00, $this->executor
        );

        $this->assertEquals(LedgerTransactionType::PaymentCard, $tx->type);
        $this->assertEquals(300.00, (float) $tx->amount);

        // Cash balance should remain at 0 (card payment doesn't add to physical cash)
        $balance = $this->ledgerService->getCurrentBalance($this->shift);
        $this->assertEquals(0.00, $balance);
    }

    public function test_withdrawal_decreases_balance(): void
    {
        $order = $this->createTestOrder();

        // Add cash first
        $this->ledgerService->recordPayment(
            $this->shift, $order, PaymentMethod::Cash, 500.00, $this->executor
        );

        // Withdraw
        $tx = $this->ledgerService->recordWithdrawal(
            $this->shift, 200.00, 'Інкасація', $this->executor
        );

        $this->assertEquals(LedgerTransactionType::Withdrawal, $tx->type);
        $this->assertEquals(-200.00, (float) $tx->amount); // negative for withdrawals

        // Balance: 0 + 500 - 200 = 300
        $balance = $this->ledgerService->getCurrentBalance($this->shift);
        $this->assertEquals(300.00, $balance);
    }

    public function test_withdrawal_with_zero_amount_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->ledgerService->recordWithdrawal(
            $this->shift, 0, 'Invalid', $this->executor
        );
    }

    public function test_reversal_undoes_cash_payment(): void
    {
        $order = $this->createTestOrder();

        // Make payment
        $payment = $this->ledgerService->recordPayment(
            $this->shift, $order, PaymentMethod::Cash, 150.00, $this->executor
        );

        // Reverse it
        $reversal = $this->ledgerService->reverseTransaction(
            $payment, $this->shift, 'Помилкова оплата', $this->executor
        );

        $this->assertEquals(LedgerTransactionType::Reversal, $reversal->type);
        $this->assertEquals(-150.00, (float) $reversal->amount);

        // Both transactions must exist (append-only)
        $this->assertEquals(2, LedgerTransaction::count());
    }

    public function test_multiple_operations_balance_is_correct(): void
    {
        $order1 = $this->createTestOrder();
        $order2 = $this->createTestOrder('COM-2604-002');

        // +500 cash
        $this->ledgerService->recordPayment(
            $this->shift, $order1, PaymentMethod::Cash, 500.00, $this->executor
        );

        // +300 card (doesn't affect cash)
        $this->ledgerService->recordPayment(
            $this->shift, $order2, PaymentMethod::Card, 300.00, $this->executor
        );

        // -100 withdrawal
        $this->ledgerService->recordWithdrawal(
            $this->shift, 100.00, 'Розмін', $this->executor
        );

        // Balance: 0 + 500 - 100 = 400 (card doesn't count)
        $balance = $this->ledgerService->getCurrentBalance($this->shift);
        $this->assertEquals(400.00, $balance);
    }

    // ─── Helpers ─────────────────────────────────────────

    private function createTestOrder(string $number = 'COM-2604-001'): Order
    {
        return Order::create([
            'order_number'     => $number,
            'order_prefix'     => 'COM',
            'order_year'       => 26,
            'order_month'      => 4,
            'order_sequence'   => (int) substr($number, -3),
            'type'             => 'commercial',
            'status'           => 'new',
            'user_id'          => $this->executor->id,
            'shift_id'         => $this->shift->id,
            'version'          => 1,
            'total_commercial' => 100.00,
            'total_cost'       => 50.00,
        ]);
    }
}
