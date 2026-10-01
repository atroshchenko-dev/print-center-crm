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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The cash routes over HTTP.
 *
 * LedgerWorkflowTest exercises LedgerService directly; nothing had ever walked
 * in through the controller, which is how it stayed at 0% of 33 instructions
 * through five audit rounds. Everything the controller owns and the service
 * does not — the shift guard, the permission gate, the audit entry, the
 * operator-facing message — lives here.
 */
class LedgerControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $executor;
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();   // Telegram must not reach the network

        $this->executor = User::factory()->create([
            'role'        => 'executor',
            'permissions' => ['orders', 'ledger'],
        ]);

        $equipment = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);

        $this->shift = app(ShiftService::class)->openShift($this->executor, [
            ['equipment_id' => $equipment->id, 'counter_value' => 10_000],
        ]);
    }

    private function fillTill(float $amount): void
    {
        $order = Order::factory()->create([
            'user_id'  => $this->executor->id,
            'shift_id' => $this->shift->id,
            'type'     => 'commercial',
        ]);

        app(LedgerService::class)->recordPayment(
            $this->shift, $order, PaymentMethod::Cash, $amount, $this->executor,
        );
    }

    // ─── history ─────────────────────────────────────────

    public function test_the_history_shows_the_shifts_entries_and_its_balance(): void
    {
        $this->fillTill(300.00);

        $response = $this->actingAs($this->executor)->get(route('ledger.history'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['transactions']);
        $this->assertEquals(300.00, (float) $props['balance']);
    }

    public function test_without_an_open_shift_the_history_sends_you_to_open_one(): void
    {
        app(ShiftService::class)->closeShift($this->shift, $this->executor);

        $this->actingAs($this->executor)
            ->get(route('ledger.history'))
            ->assertRedirect(route('shifts.open.form'));
    }

    public function test_the_cash_routes_need_the_ledger_permission(): void
    {
        $other = User::factory()->create([
            'role'        => 'executor',
            'permissions' => ['orders'],
        ]);

        $this->actingAs($other)->get(route('ledger.history'))->assertForbidden();
        $this->actingAs($other)
            ->post(route('ledger.withdrawal'), ['amount' => 10, 'comment' => 'Розмін'])
            ->assertForbidden();
    }

    // ─── withdrawal ──────────────────────────────────────

    public function test_a_withdrawal_is_recorded_and_audited(): void
    {
        $this->fillTill(500.00);

        $this->actingAs($this->executor)
            ->post(route('ledger.withdrawal'), ['amount' => 200, 'comment' => 'Інкасація'])
            ->assertSessionHas('success');

        $tx = LedgerTransaction::where('type', LedgerTransactionType::Withdrawal->value)->sole();
        $this->assertEquals(-200.00, (float) $tx->amount);
        $this->assertEquals(300.00, (float) $tx->balance_after);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'cash_withdrawal',
            'shift_id'   => $this->shift->id,
        ]);
    }

    public function test_a_withdrawal_needs_an_amount_and_a_reason(): void
    {
        $this->fillTill(500.00);

        $this->actingAs($this->executor)
            ->post(route('ledger.withdrawal'), ['amount' => 0, 'comment' => 'Інкасація'])
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->executor)
            ->post(route('ledger.withdrawal'), ['amount' => 100, 'comment' => 'ок'])
            ->assertSessionHasErrors('comment');

        $this->assertSame(0, LedgerTransaction::where('type', LedgerTransactionType::Withdrawal->value)->count());
    }

    /**
     * You cannot hand out money that is not in the drawer.
     *
     * Every other resource in this system refuses to go below zero — installing
     * a toner that is not on the shelf throws, converting more paper than there
     * is throws, a deduction is clamped at the stock on hand. Cash was the one
     * that accepted anything: a mistyped 5000 instead of 500 was journalled,
     * the till went to minus, and because the journal is append-only the only
     * way back is a reversal. Nobody sees it until the next close, where it
     * looks exactly like a shortfall.
     */
    public function test_more_cash_than_the_till_holds_cannot_be_taken_out(): void
    {
        $this->fillTill(500.00);

        $this->actingAs($this->executor)
            ->post(route('ledger.withdrawal'), ['amount' => 5000, 'comment' => 'Помилка вводу'])
            ->assertSessionHas('error');

        $this->assertSame(
            0,
            LedgerTransaction::where('type', LedgerTransactionType::Withdrawal->value)->count(),
            'A withdrawal bigger than the till was written into an append-only journal.',
        );
        $this->assertEquals(500.00, $this->shift->fresh()->calculateExpectedBalance());
    }

    public function test_the_whole_till_can_be_taken_out(): void
    {
        $this->fillTill(500.00);

        $this->actingAs($this->executor)
            ->post(route('ledger.withdrawal'), ['amount' => 500, 'comment' => 'Інкасація повна'])
            ->assertSessionHas('success');

        $this->assertEquals(0.0, $this->shift->fresh()->calculateExpectedBalance());
    }

    /**
     * A card payment is in the journal but not in the drawer. Counting it as
     * available cash would let the operator hand out money that only exists on
     * a bank statement.
     */
    public function test_a_card_payment_does_not_make_cash_available(): void
    {
        $order = Order::factory()->create([
            'user_id'  => $this->executor->id,
            'shift_id' => $this->shift->id,
            'type'     => 'commercial',
        ]);
        app(LedgerService::class)->recordPayment(
            $this->shift, $order, PaymentMethod::Card, 900.00, $this->executor,
        );

        $this->actingAs($this->executor)
            ->post(route('ledger.withdrawal'), ['amount' => 100, 'comment' => 'Розмін'])
            ->assertSessionHas('error');
    }
}
