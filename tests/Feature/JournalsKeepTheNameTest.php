<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LedgerTransactionType;
use App\Exports\AuditLogExport;
use App\Exports\CounterAnalyticsExport;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Shift;
use App\Models\ShiftCounterReading;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A journal names who acted, and keeps naming them.
 *
 * `AuditLog`, `LedgerTransaction`, `OrderStatusHistory` and
 * `ShiftCounterReading` all guard themselves with four methods that throw:
 * the row cannot be updated, cannot be deleted, cannot be force-deleted. And
 * deactivating the person or the machine named in it rewrote every one of them
 * anyway — `belongsTo` applies the soft-delete scope, so the relation came back
 * null and the page printed an empty cell. The audit log **export** was worse
 * than empty: `?? 'Система'` turned a person's action into the system's.
 *
 * Nothing was lost from the database. `user_id` still points where it pointed.
 * What was lost is the answer to «хто це зробив», on exactly the day it starts
 * being asked — the one after somebody left.
 *
 * The shape is R19-1 and R20-3 for the third time, and the fix already exists
 * three times over on the reference pages (`with(['group' => …withTrashed()])`,
 * `UniversityDeactivatedRowsTest`). It was never carried to the journals, which
 * are the records that matter most and are read least often.
 */
class JournalsKeepTheNameTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role'        => 'admin',
            'permissions' => User::permissionKeys(),
        ]);
    }

    /** A person who worked here, was recorded, and has since been deactivated. */
    private function departed(string $name = 'Петренко Петро'): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'executor']);
        $user->delete();

        return $user;
    }

    // ─── The audit journal ───────────────────────────────

    public function test_the_audit_log_names_a_deactivated_user(): void
    {
        $departed = $this->departed();
        AuditLog::record('order_cancelled', $departed, 'Скасовано замовлення #1');

        $this->actingAs($this->admin)
            ->get(route('reports.audit'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('logs.data.0.user.name', 'Петренко Петро'));
    }

    /**
     * The export is the half that did not merely go quiet.
     *
     * `Система` is a real value in this column — `AuditLog::record()` takes a
     * nullable user, and scheduled work writes rows with no one behind them.
     * Handing a departed employee's entries the same label does not lose the
     * name, it replaces it with a false one.
     */
    public function test_the_audit_export_does_not_file_a_departed_user_as_the_system(): void
    {
        $departed = $this->departed();
        $log = AuditLog::record('cash_withdrawal', $departed, 'Видача готівки');

        $row = (new AuditLogExport(
            Carbon::now()->startOfDay(),
            Carbon::now()->endOfDay(),
        ))->map($log->fresh());

        $this->assertSame('Петренко Петро', $row[3]);
    }

    public function test_the_system_is_still_the_system(): void
    {
        $log = AuditLog::record('shift_auto_closed', null, 'Зміна закрита автоматично');

        $row = (new AuditLogExport(
            Carbon::now()->startOfDay(),
            Carbon::now()->endOfDay(),
        ))->map($log->fresh());

        $this->assertSame('Система', $row[3]);
    }

    /**
     * The filter has to offer them, or the journal keeps their entries and the
     * page refuses the one question worth asking about someone who has left.
     */
    public function test_the_user_filter_offers_deactivated_people_and_says_so(): void
    {
        $departed = $this->departed();

        $response = $this->actingAs($this->admin)->get(route('reports.audit'));
        $response->assertOk();

        $offered = collect($response->viewData('page')['props']['users'])
            ->firstWhere('id', $departed->id);

        $this->assertNotNull($offered, 'A departed user cannot be filtered for at all.');
        $this->assertSame('Петренко Петро', $offered['name']);
        $this->assertNotNull($offered['deleted_at'], 'The option has to say the person is deactivated.');
    }

    /**
     * The journal's filters come from the query string, and a mistyped
     * `user_id` went into the bigint column raw — Postgres 22P02, a 500 and
     * a Telegram alert for a typo in the URL.
     */
    public function test_the_journal_answers_a_mistyped_filter_with_a_page_not_a_crash(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.audit', ['user_id' => 'abc', 'event_type' => ['x']]))
            ->assertOk();
    }

    // ─── The money journal ───────────────────────────────

    public function test_cash_history_names_a_deactivated_user(): void
    {
        $departed = $this->departed();
        $shift = Shift::factory()->create(['status' => 'open', 'date' => today('Europe/Kyiv')]);

        LedgerTransaction::create([
            'shift_id'      => $shift->id,
            'order_id'      => null,
            'type'          => LedgerTransactionType::Withdrawal->value,
            'amount'        => -100,
            'balance_after' => 0,
            'comment'       => 'Видача',
            'user_id'       => $departed->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('ledger.history'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('transactions.0.user.name', 'Петренко Петро'));
    }

    // ─── The order's own history ─────────────────────────

    public function test_an_order_keeps_the_name_of_whoever_moved_it(): void
    {
        $departed = $this->departed();
        $shift = Shift::factory()->create(['status' => 'open', 'date' => today('Europe/Kyiv')]);
        $order = Order::factory()->create(['shift_id' => $shift->id, 'user_id' => $departed->id]);

        OrderStatusHistory::record($order, 'new', 'in_progress', $departed);

        $this->actingAs($this->admin)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.user.name', 'Петренко Петро')
                ->where('order.status_history.0.user.name', 'Петренко Петро'));
    }

    // ─── The counter journal ─────────────────────────────

    private function reading(Shift $shift, Equipment $equipment, User $user, int $value): ShiftCounterReading
    {
        $reading = new ShiftCounterReading;
        $reading->shift_id = $shift->id;
        $reading->equipment_id = $equipment->id;
        $reading->user_id = $user->id;
        $reading->reading_type = 'morning';
        $reading->counter_value = $value;
        $reading->created_at = Carbon::parse($shift->date->toDateString().' 09:00:00');
        $reading->save();

        return $reading;
    }

    public function test_the_counter_export_names_a_retired_machine(): void
    {
        $equipment = Equipment::factory()->create(['name' => 'Ricoh MP', 'type' => 'bw', 'has_counter' => true]);
        $shift = Shift::factory()->create(['date' => '2026-07-15', 'status' => 'closed']);
        $reading = $this->reading($shift, $equipment, $this->admin, 100);

        $equipment->delete();

        $export = new CounterAnalyticsExport(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $this->assertSame('Ricoh MP', $export->map($reading->fresh())[1]);
    }

    /**
     * A report about July must not change in August.
     *
     * The analytics table was built from `is_active = true` and today's fleet:
     * retiring a machine erased its rows from every period it had ever worked,
     * while its readings stayed listed underneath, attached to no figure.
     */
    public function test_retiring_a_machine_does_not_rewrite_the_periods_it_worked(): void
    {
        $equipment = Equipment::factory()->create(['name' => 'Ricoh MP', 'type' => 'bw', 'has_counter' => true]);
        $first = Shift::factory()->create(['date' => '2026-07-10', 'status' => 'closed']);
        $last = Shift::factory()->create(['date' => '2026-07-20', 'status' => 'closed']);

        $this->reading($first, $equipment, $this->admin, 1_000);
        $this->reading($last, $equipment, $this->admin, 1_500);

        $before = $this->analytics();

        $equipment->delete();

        $after = $this->analytics();

        $this->assertCount(1, $after, 'The machine that gave these readings left the report with them.');
        $this->assertSame('Ricoh MP', $after[0]['equipment_name']);
        $this->assertSame($before[0]['physical_delta'], $after[0]['physical_delta']);
        $this->assertTrue($after[0]['is_retired'], 'The row has to say the machine is no longer in the room.');
    }

    /**
     * The attribution guard counted today's fleet, so replacing a machine
     * mid-period handed the survivor's row the whole column and told it, in the
     * one case the guard exists for, that the clicks were its own.
     */
    public function test_a_machine_replaced_mid_period_still_shares_its_column(): void
    {
        $retired = Equipment::factory()->create(['name' => 'Ricoh старий', 'type' => 'bw', 'has_counter' => true]);
        $current = Equipment::factory()->create(['name' => 'Ricoh новий', 'type' => 'bw', 'has_counter' => true]);

        $shift = Shift::factory()->create(['date' => '2026-07-10', 'status' => 'closed']);
        $this->reading($shift, $retired, $this->admin, 1_000);

        $retired->delete();

        $rows = collect($this->analytics())->keyBy('equipment_name');

        $this->assertCount(2, $rows);
        $this->assertFalse($rows['Ricoh новий']['clicks_are_this_machines']);
        $this->assertFalse($rows['Ricoh старий']['clicks_are_this_machines']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function analytics(): array
    {
        $response = $this->actingAs($this->admin)->get(route('reports.counters', [
            'from' => '2026-07-01',
            'to'   => '2026-07-31',
        ]));
        $response->assertOk();

        return array_values(collect($response->viewData('page')['props']['counterAnalytics'])->all());
    }
}
