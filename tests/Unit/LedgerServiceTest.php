<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\ShiftStatus;
use App\Models\LedgerTransaction;
use App\Models\Shift;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $service;
    private Shift $shift;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LedgerService::class);
        $this->user = User::factory()->create(['role' => 'executor']);
        $this->shift = Shift::factory()->create([
            'status'     => ShiftStatus::Open->value,
            'cash_start' => 500.00,
        ]);
    }

    // ─── Balance Calculation ─────────────────────────────

    public function test_initial_balance_equals_cash_start(): void
    {
        $balance = $this->service->getCurrentBalance($this->shift);
        $this->assertEquals(500.00, $balance);
    }

    public function test_cash_payment_increases_balance(): void
    {
        $order = \App\Models\Order::factory()->create(['shift_id' => $this->shift->id]);

        $this->service->recordPayment(
            $this->shift, $order, PaymentMethod::Cash, 100.00, $this->user,
        );

        $balance = $this->service->getCurrentBalance($this->shift);
        $this->assertEquals(600.00, $balance);
    }

    public function test_card_payment_does_not_affect_balance(): void
    {
        $order = \App\Models\Order::factory()->create(['shift_id' => $this->shift->id]);

        $this->service->recordPayment(
            $this->shift, $order, PaymentMethod::Card, 200.00, $this->user,
        );

        $balance = $this->service->getCurrentBalance($this->shift);
        $this->assertEquals(500.00, $balance); // Unchanged
    }

    // ─── Withdrawals ─────────────────────────────────────

    public function test_withdrawal_decreases_balance(): void
    {
        $this->service->recordWithdrawal(
            $this->shift, 150.00, 'Інкасація', $this->user,
        );

        $balance = $this->service->getCurrentBalance($this->shift);
        $this->assertEquals(350.00, $balance);
    }

    public function test_withdrawal_stores_negative_amount(): void
    {
        $tx = $this->service->recordWithdrawal(
            $this->shift, 100.00, 'Test', $this->user,
        );

        $this->assertEquals(-100.00, (float) $tx->amount);
        $this->assertEquals(LedgerTransactionType::Withdrawal, $tx->type);
    }

    public function test_withdrawal_rejects_zero_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->recordWithdrawal(
            $this->shift, 0, 'Zero', $this->user,
        );
    }

    public function test_withdrawal_rejects_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->recordWithdrawal(
            $this->shift, -50, 'Negative', $this->user,
        );
    }

    // ─── Reversals ───────────────────────────────────────

    public function test_cash_reversal_adjusts_balance(): void
    {
        $order = \App\Models\Order::factory()->create(['shift_id' => $this->shift->id]);

        $payment = $this->service->recordPayment(
            $this->shift, $order, PaymentMethod::Cash, 200.00, $this->user,
        );

        $this->assertEquals(700.00, $this->service->getCurrentBalance($this->shift));

        $this->service->reverseTransaction(
            $payment, $this->shift, 'Error correction', $this->user,
        );

        $this->assertEquals(500.00, $this->service->getCurrentBalance($this->shift));
    }

    /**
     * A reversal copies the payment method of the entry it undoes, and a
     * withdrawal has none. calculateExpectedBalance() only counted reversals
     * marked 'cash', so an undone withdrawal left the money out of the till
     * while LedgerService had already written a balance_after that included
     * it — the recomputed balance and the journal disagreed from then on.
     */
    public function test_reversing_a_withdrawal_puts_the_cash_back(): void
    {
        $withdrawal = $this->service->recordWithdrawal(
            $this->shift, 150.00, 'Інкасація', $this->user,
        );

        $this->assertEquals(350.00, $this->service->getCurrentBalance($this->shift));

        $reversal = $this->service->reverseTransaction(
            $withdrawal, $this->shift, 'Порахували двічі', $this->user,
        );

        $this->assertEquals(500.00, $this->service->getCurrentBalance($this->shift));
        $this->assertEquals(
            500.00,
            (float) $reversal->balance_after,
            'The journal and the recomputed balance have to say the same thing.',
        );
    }

    public function test_card_reversal_does_not_affect_cash_balance(): void
    {
        $order = \App\Models\Order::factory()->create(['shift_id' => $this->shift->id]);

        $payment = $this->service->recordPayment(
            $this->shift, $order, PaymentMethod::Card, 300.00, $this->user,
        );

        $this->assertEquals(500.00, $this->service->getCurrentBalance($this->shift));

        $this->service->reverseTransaction(
            $payment, $this->shift, 'Card refund', $this->user,
        );

        // Card reversal doesn't change cash
        $this->assertEquals(500.00, $this->service->getCurrentBalance($this->shift));
    }

    // ─── Immutability ────────────────────────────────────

    public function test_ledger_transaction_cannot_be_updated(): void
    {
        $order = \App\Models\Order::factory()->create(['shift_id' => $this->shift->id]);

        $tx = $this->service->recordPayment(
            $this->shift, $order, PaymentMethod::Cash, 100.00, $this->user,
        );

        $this->expectException(\RuntimeException::class);
        $tx->update(['amount' => 999]);
    }

    public function test_ledger_transaction_cannot_be_deleted(): void
    {
        $order = \App\Models\Order::factory()->create(['shift_id' => $this->shift->id]);

        $tx = $this->service->recordPayment(
            $this->shift, $order, PaymentMethod::Cash, 100.00, $this->user,
        );

        $this->expectException(\RuntimeException::class);
        $tx->delete();
    }

    // ─── Complex Scenario ────────────────────────────────

    public function test_mixed_operations_calculate_correctly(): void
    {
        $order1 = \App\Models\Order::factory()->create(['shift_id' => $this->shift->id]);
        $order2 = \App\Models\Order::factory()->create(['shift_id' => $this->shift->id]);

        // Cash payment +200
        $this->service->recordPayment($this->shift, $order1, PaymentMethod::Cash, 200.00, $this->user);
        // Card payment (no cash effect)
        $this->service->recordPayment($this->shift, $order2, PaymentMethod::Card, 150.00, $this->user);
        // Withdrawal -80
        $this->service->recordWithdrawal($this->shift, 80.00, 'Change', $this->user);

        // Expected: 500 + 200 - 80 = 620
        $this->assertEquals(620.00, $this->service->getCurrentBalance($this->shift));
    }
}
