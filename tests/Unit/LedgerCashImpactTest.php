<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\ShiftStatus;
use App\Models\LedgerTransaction;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Does this entry move physical cash" is answered twice, and the two answers
 * have to be the same number:
 *
 *   - LedgerTransaction::affectsCashBalance() decides it for one entry, and
 *     LedgerService writes balance_after from that decision;
 *   - Shift::calculateExpectedBalance() recomputes the whole shift in SQL.
 *
 * They drifted apart once, over reversed withdrawals: the SQL
 * dropped them and the till came out short by money that had been put back.
 * Nothing tied the two together, so this walks every type.
 */
class LedgerCashImpactTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shift;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user  = User::factory()->create();
        $this->shift = Shift::factory()->create([
            'opened_by'  => $this->user->id,
            'status'     => ShiftStatus::Open,
            'cash_start' => 1000.00,
        ]);
    }

    private function entry(LedgerTransactionType $type, ?PaymentMethod $method, float $amount): LedgerTransaction
    {
        return LedgerTransaction::create([
            'shift_id'       => $this->shift->id,
            'type'           => $type->value,
            'payment_method' => $method?->value,
            'amount'         => $amount,
            'balance_after'  => 0,   // recomputed below, never trusted here
            'user_id'        => $this->user->id,
        ]);
    }

    /**
     * @return array<string, array{0: LedgerTransactionType, 1: ?PaymentMethod, 2: float, 3: bool}>
     */
    public static function entries(): array
    {
        return [
            'cash payment'        => [LedgerTransactionType::PaymentCash, PaymentMethod::Cash, 250.00, true],
            'card payment'        => [LedgerTransactionType::PaymentCard, PaymentMethod::Card, 250.00, false],
            'withdrawal'          => [LedgerTransactionType::Withdrawal,  null,               -80.00, true],
            'reversed cash'       => [LedgerTransactionType::Reversal,    PaymentMethod::Cash, -250.00, false],
            'reversed card'       => [LedgerTransactionType::Reversal,    PaymentMethod::Card, -250.00, false],
            'reversed withdrawal' => [LedgerTransactionType::Reversal,    null,                 80.00, false],
        ];
    }

    /**
     * The per-entry rule and the shift-wide sum must move the balance by the
     * same amount — or by nothing, together.
     *
     * A reversal is `false` for the per-entry rule on purpose: that method is
     * asked about the entry being *undone*, never about the reversal itself.
     * The sum, on the other hand, does count reversals, and this pins the pair
     * of them: the recomputed balance is what changes, and the per-entry rule
     * only has to agree about the originals.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('entries')]
    public function test_the_two_definitions_agree_on_every_type(
        LedgerTransactionType $type,
        ?PaymentMethod $method,
        float $amount,
        bool $perEntryAffectsCash,
    ): void {
        $before = $this->shift->calculateExpectedBalance();

        $entry = $this->entry($type, $method, $amount);

        $after = $this->shift->fresh()->calculateExpectedBalance();
        $moved = round($after - $before, 2) !== 0.0;

        $this->assertSame(
            $perEntryAffectsCash,
            $entry->affectsCashBalance(),
            "Per-entry rule changed for '{$type->value}'.",
        );

        // A reversal moves the sum but is never itself passed to the per-entry
        // rule; every other type has to answer the same way in both.
        if ($type !== LedgerTransactionType::Reversal) {
            $this->assertSame(
                $moved,
                $entry->affectsCashBalance(),
                "The sum and the per-entry rule disagree about '{$type->value}'.",
            );
        }
    }

    /**
     * The case R5-9 was about, kept as its own assertion because the money is
     * the point: cash goes out, the withdrawal is reversed, and the till has
     * to be back where it started.
     */
    public function test_reversing_a_withdrawal_puts_the_money_back(): void
    {
        $withdrawal = $this->entry(LedgerTransactionType::Withdrawal, null, -300.00);
        $this->assertEquals(700.00, $this->shift->fresh()->calculateExpectedBalance());

        app(\App\Services\LedgerService::class)->reverseTransaction(
            original:     $withdrawal,
            currentShift: $this->shift,
            reason:       'Помилкова виїмка',
            user:         $this->user,
        );

        $this->assertEquals(
            1000.00,
            $this->shift->fresh()->calculateExpectedBalance(),
            'Money put back into the till has to show in the till.',
        );
    }

    /**
     * balance_after is what the ledger history screen shows line by line, and
     * it is written from the per-entry rule. It has to land on the same number
     * the shift-wide sum produces, or the screen and the close disagree.
     */
    public function test_the_written_balance_matches_the_recomputed_one(): void
    {
        $service = app(\App\Services\LedgerService::class);
        $order   = \App\Models\Order::factory()->create(['user_id' => $this->user->id]);

        $cash = $service->recordPayment($this->shift, $order, PaymentMethod::Cash, 150.00, $this->user);
        $this->assertEquals(1150.00, (float) $cash->balance_after);
        $this->assertEquals(1150.00, $this->shift->fresh()->calculateExpectedBalance());

        $card = $service->recordPayment($this->shift, $order, PaymentMethod::Card, 400.00, $this->user);
        $this->assertEquals(1150.00, (float) $card->balance_after, 'A card payment is not cash in the drawer.');
        $this->assertEquals(1150.00, $this->shift->fresh()->calculateExpectedBalance());

        $out = $service->recordWithdrawal($this->shift, 50.00, 'Розмін', $this->user);
        $this->assertEquals(1100.00, (float) $out->balance_after);
        $this->assertEquals(1100.00, $this->shift->fresh()->calculateExpectedBalance());
    }

    /**
     * The type a payment is booked under comes from the method, in one place.
     */
    public function test_the_payment_method_names_its_own_ledger_type(): void
    {
        $this->assertSame(LedgerTransactionType::PaymentCash, PaymentMethod::Cash->ledgerType());
        $this->assertSame(LedgerTransactionType::PaymentCard, PaymentMethod::Card->ledgerType());
        $this->assertTrue(PaymentMethod::Cash->affectsCashBalance());
        $this->assertFalse(PaymentMethod::Card->affectsCashBalance());
    }

    /**
     * shift_start is in the enum and in the migration's comment, and nothing
     * writes it. The opening till is shifts.cash_start, which the balance
     * formula starts from — so a journal row of this type would be counted by
     * nobody. This fails the day something starts writing one.
     */
    public function test_nothing_writes_a_shift_start_entry(): void
    {
        app(\App\Services\ShiftCloseService::class)->closeShift($this->shift, $this->user);

        $this->assertSame(
            0,
            LedgerTransaction::where('type', LedgerTransactionType::ShiftStart->value)->count(),
            'A shift_start row exists but the balance formula does not sum it.',
        );
    }
}
