<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LedgerTransactionType;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * LedgerTransactionTest
 *
 * Critical: verifies that the append-only invariant of LedgerTransaction
 * is enforced at the Model level (save() guard + delete() guard).
 */
class LedgerTransactionTest extends TestCase
{
    use RefreshDatabase;

    private LedgerTransaction $tx;

    /**
     * The one open shift. Only one may exist at a time, so a reversal lands in
     * the shift that is already running rather than in a second one.
     */
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $user  = User::factory()->create(['role' => 'executor']);
        $this->shift = Shift::factory()->create(['status' => 'open', 'opened_by' => $user->id]);

        // Create a new, valid transaction (INSERT is allowed)
        $this->tx = LedgerTransaction::create([
            'shift_id'      => $this->shift->id,
            'user_id'       => $user->id,
            'type'          => LedgerTransactionType::PaymentCash->value,
            'amount'        => 100.00,
            'balance_after' => 100.00,
            'comment'       => 'Test payment',
        ]);
    }

    /**
     * INSERT should work — create() bypasses the save() guard.
     */
    public function test_create_is_allowed(): void
    {
        $this->assertNotNull($this->tx->id);
        $this->assertEquals(100.00, $this->tx->amount);
    }

    /**
     * UPDATE via save() must throw RuntimeException.
     */
    public function test_update_via_save_throws_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/immutable/i');

        $this->tx->amount = 999.00;
        $this->tx->save();  // must throw
    }

    /**
     * UPDATE via Eloquent update() must throw RuntimeException.
     */
    public function test_update_via_eloquent_update_throws_exception(): void
    {
        $this->expectException(RuntimeException::class);

        $this->tx->update(['amount' => 999.00]);  // must throw
    }

    /**
     * DELETE must throw RuntimeException.
     */
    public function test_delete_throws_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/immutable/i');

        $this->tx->delete();
    }

    /**
     * Reversal transaction must be created as a NEW row with negative amount
     * and must reference the original transaction.
     */
    public function test_create_reversal_creates_new_row(): void
    {
        $user  = User::factory()->create();
        $shift = $this->shift;

        $reversal = LedgerTransaction::createReversal(
            original:        $this->tx,
            currentShiftId:  $shift->id,
            comment:         'Test reversal',
            userId:          $user->id,
            newBalanceAfter: 0.00,
        );

        // Must be a new row
        $this->assertNotEquals($this->tx->id, $reversal->id);

        // Amount must be negative of original
        $this->assertEquals(-100.00, (float) $reversal->amount);

        // Type must be reversal
        $this->assertEquals(LedgerTransactionType::Reversal, $reversal->type);

        // The link is a real column now, not just prose in the comment
        $this->assertEquals($this->tx->id, $reversal->reversed_transaction_id);
        $this->assertTrue($this->tx->fresh()->isReversed());
        $this->assertEquals($reversal->id, $this->tx->fresh()->reversal->id);
    }

    // ─── Double reversal ─────────────────────────────────────
    //
    // Reversing the same payment twice would hand the customer their money
    // back twice and leave the shift short by that amount. Until now the only
    // thing preventing it was the order status machine at the two call sites;
    // these tests pin the rule to the journal itself.

    public function test_reversing_the_same_transaction_twice_throws(): void
    {
        $user  = User::factory()->create();
        $shift = $this->shift;

        LedgerTransaction::createReversal(
            original:        $this->tx,
            currentShiftId:  $shift->id,
            comment:         'Customer refused the order',
            userId:          $user->id,
            newBalanceAfter: 0.00,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been reversed/i');

        LedgerTransaction::createReversal(
            original:        $this->tx,
            currentShiftId:  $shift->id,
            comment:         'And again',
            userId:          $user->id,
            newBalanceAfter: -100.00,
        );
    }

    public function test_a_reversal_cannot_itself_be_reversed(): void
    {
        $user  = User::factory()->create();
        $shift = $this->shift;

        $reversal = LedgerTransaction::createReversal(
            original:        $this->tx,
            currentShiftId:  $shift->id,
            comment:         'Customer refused the order',
            userId:          $user->id,
            newBalanceAfter: 0.00,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/itself a reversal/i');

        LedgerTransaction::createReversal(
            original:        $reversal,
            currentShiftId:  $shift->id,
            comment:         'Undo the undo',
            userId:          $user->id,
            newBalanceAfter: 100.00,
        );
    }

    /**
     * The model check can be raced; the partial unique index cannot. This goes
     * straight to the table to prove the guard survives a caller that skips
     * createReversal() entirely.
     */
    public function test_database_rejects_a_second_reversal_row_for_the_same_transaction(): void
    {
        $user  = User::factory()->create();
        $shift = $this->shift;

        $row = [
            'shift_id'                => $shift->id,
            'reversed_transaction_id' => $this->tx->id,
            'type'                    => LedgerTransactionType::Reversal->value,
            'amount'                  => -100.00,
            'balance_after'           => 0.00,
            'user_id'                 => $user->id,
            'created_at'              => now(),
        ];

        DB::table('ledger_transactions')->insert($row);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('ledger_transactions')->insert($row);
    }

    /**
     * The partial index must not turn "never reversed" into a unique value —
     * otherwise the second ordinary transaction of the day would be rejected.
     */
    public function test_many_transactions_may_share_a_null_reversal_link(): void
    {
        $user  = User::factory()->create();
        $shift = $this->shift;

        foreach ([10.00, 20.00, 30.00] as $amount) {
            LedgerTransaction::create([
                'shift_id'      => $shift->id,
                'user_id'       => $user->id,
                'type'          => LedgerTransactionType::PaymentCash->value,
                'amount'        => $amount,
                'balance_after' => $amount,
            ]);
        }

        $rows = LedgerTransaction::where('shift_id', $shift->id)
            ->whereIn('amount', [10.00, 20.00, 30.00])
            ->get();

        $this->assertCount(3, $rows);
        $this->assertTrue(
            $rows->every(fn ($t) => $t->reversed_transaction_id === null),
            'All three are unreversed, and that must not collide.',
        );
    }
}
