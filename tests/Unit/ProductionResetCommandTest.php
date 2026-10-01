<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CostCenterInitiator;
use App\Models\Department;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Shift;
use App\Models\SignatoryCostCenter;
use App\Models\UniversityRef;
use App\Models\User;
use App\Support\KyivClock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ProductionResetCommandTest — Validates the destructive production:reset command.
 *
 * This command is the ONLY place in the system that physically deletes data
 * (bypasses SoftDeletes by using DB::table()->delete() with triggers disabled).
 *
 * Critical invariants to verify:
 *   1. All transactional data is purged
 *   2. Reference data is PRESERVED (services, equipment, users, inventory items)
 *   3. Immutability triggers are re-enabled after reset
 *   4. Seed shift is created with correct cash balance
 *   5. Equipment counters are updated
 */
class ProductionResetCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $this->equipment = Equipment::factory()->create([
            'name'            => 'Konica Minolta bizhub 283',
            'type'            => 'bw',
            'is_active'       => true,
            'has_counter'     => true,
            'initial_counter' => 0,
        ]);

        // Create RISO equipment
        Equipment::factory()->create([
            'name'        => 'Ricoh DD4450',
            'type'        => 'riso',
            'is_active'   => true,
            'has_counter' => true,
        ]);
    }

    public function test_reset_clears_all_transactional_data(): void
    {
        // Create test data
        $shift = Shift::factory()->create(['opened_by' => $this->admin->id]);
        $order = Order::factory()->forShift($shift)->create(['user_id' => $this->admin->id]);

        // Use DB directly for append-only tables (model guards prevent create in some cases)
        \DB::table('ledger_transactions')->insert([
            'shift_id' => $shift->id, 'order_id' => $order->id,
            'type'     => 'payment_cash', 'amount' => 100, 'balance_after' => 100,
            'user_id'  => $this->admin->id, 'created_at' => now(),
        ]);
        \DB::table('audit_logs')->insert([
            'event_type'  => 'test', 'user_id' => $this->admin->id,
            'description' => 'Test log', 'created_at' => now(),
        ]);
        \DB::table('shift_counter_readings')->insert([
            'shift_id'     => $shift->id, 'equipment_id' => $this->equipment->id,
            'reading_type' => 'morning', 'counter_value' => 100,
            'user_id'      => $this->admin->id, 'created_at' => now(),
        ]);

        // Run the command (auto-confirm)
        $this->artisan('production:reset', ['--cash' => '5000'])
            ->expectsConfirmation('Are you absolutely sure? Type YES to proceed', 'yes')
            ->expectsConfirmation('LAST CHANCE: This cannot be undone. Continue?', 'yes')
            ->assertExitCode(0);

        // Verify all transactional data is gone
        $this->assertEquals(0, \DB::table('orders')->count());
        $this->assertEquals(0, \DB::table('order_items')->count());
        $this->assertEquals(0, \DB::table('ledger_transactions')->count());
        $this->assertEquals(0, \DB::table('audit_logs')->count());
        $this->assertEquals(0, \DB::table('inventory_movements')->count());

        // Verify seed shift was created (so count = 1)
        $this->assertEquals(1, \DB::table('shifts')->count());
    }

    public function test_reset_preserves_reference_data(): void
    {
        // Create reference data
        $serviceCategory = ServiceCategory::factory()->create();
        $service = Service::factory()->create(['service_category_id' => $serviceCategory->id]);

        $usersBefore = User::count();
        $equipmentBefore = Equipment::count();

        $this->artisan('production:reset', ['--cash' => '5000'])
            ->expectsConfirmation('Are you absolutely sure? Type YES to proceed', 'yes')
            ->expectsConfirmation('LAST CHANCE: This cannot be undone. Continue?', 'yes')
            ->assertExitCode(0);

        // Reference data must survive
        $this->assertEquals($usersBefore, User::count());
        $this->assertEquals($equipmentBefore, Equipment::count());
        $this->assertNotNull(Service::find($service->id));
        $this->assertNotNull(ServiceCategory::find($serviceCategory->id));
    }

    public function test_reset_creates_seed_shift_with_correct_cash(): void
    {
        $this->artisan('production:reset', ['--cash' => '7600'])
            ->expectsConfirmation('Are you absolutely sure? Type YES to proceed', 'yes')
            ->expectsConfirmation('LAST CHANCE: This cannot be undone. Continue?', 'yes')
            ->assertExitCode(0);

        $seedShift = \DB::table('shifts')->first();
        $this->assertNotNull($seedShift);
        $this->assertEquals('closed', $seedShift->status);
        $this->assertEquals(7600.00, (float) $seedShift->cash_start);
        $this->assertEquals(7600.00, (float) $seedShift->cash_actual);
        $this->assertEquals(7600.00, (float) $seedShift->cash_calculated);
    }

    /**
     * The seed shift's timestamps are instants, and instants live in UTC
     * (finding I-3, 2026-08-09 audit). The command built them as Kyiv wall
     * clocks — setTime(9, 0) on a Europe/Kyiv Carbon — and inserted them
     * unconverted, so the stored "09:00" read back as 11:00 or 12:00 on the
     * Kyiv wall depending on DST. KyivClock's docblock lists this exact
     * mistake as R7-2; the command just had not crossed the line through it.
     */
    public function test_seed_shift_timestamps_are_stored_in_utc(): void
    {
        $this->artisan('production:reset', ['--cash' => '5000'])
            ->expectsConfirmation('Are you absolutely sure? Type YES to proceed', 'yes')
            ->expectsConfirmation('LAST CHANCE: This cannot be undone. Continue?', 'yes')
            ->assertExitCode(0);

        $seedShift = \DB::table('shifts')->first();

        $openedAt = Carbon::parse($seedShift->opened_at, 'UTC');
        $closedAt = Carbon::parse($seedShift->closed_at, 'UTC');

        $this->assertSame(
            '09:00',
            KyivClock::format($openedAt, 'H:i'),
            'The seed shift opens at 09:00 on the Kyiv wall clock.',
        );
        $this->assertSame(
            '18:00',
            KyivClock::format($closedAt, 'H:i'),
            'The seed shift closes at 18:00 on the Kyiv wall clock.',
        );
        $this->assertSame(
            $seedShift->date,
            KyivClock::format($openedAt),
            'The business date and the opening instant must name the same Kyiv day.',
        );
    }

    public function test_reset_updates_equipment_counters(): void
    {
        $this->artisan('production:reset', ['--cash' => '5000'])
            ->expectsConfirmation('Are you absolutely sure? Type YES to proceed', 'yes')
            ->expectsConfirmation('LAST CHANCE: This cannot be undone. Continue?', 'yes')
            ->assertExitCode(0);

        $this->equipment->refresh();
        $this->assertEquals(2442646, $this->equipment->initial_counter);
    }

    public function test_reset_disables_riso_counter(): void
    {
        $this->artisan('production:reset', ['--cash' => '5000'])
            ->expectsConfirmation('Are you absolutely sure? Type YES to proceed', 'yes')
            ->expectsConfirmation('LAST CHANCE: This cannot be undone. Continue?', 'yes')
            ->assertExitCode(0);

        $riso = Equipment::where('name', 'Ricoh DD4450')->first();
        $this->assertFalse($riso->has_counter);
    }

    public function test_abort_on_first_confirmation_does_nothing(): void
    {
        $shift = Shift::factory()->create(['opened_by' => $this->admin->id]);

        $this->artisan('production:reset', ['--cash' => '5000'])
            ->expectsConfirmation('Are you absolutely sure? Type YES to proceed', 'no')
            ->assertExitCode(0);

        // Data must still exist
        $this->assertEquals(1, Shift::count());
    }

    public function test_abort_on_second_confirmation_does_nothing(): void
    {
        $shift = Shift::factory()->create(['opened_by' => $this->admin->id]);

        $this->artisan('production:reset', ['--cash' => '5000'])
            ->expectsConfirmation('Are you absolutely sure? Type YES to proceed', 'yes')
            ->expectsConfirmation('LAST CHANCE: This cannot be undone. Continue?', 'no')
            ->assertExitCode(0);

        // Data must still exist
        $this->assertEquals(1, Shift::count());
    }

    /**
     * Обидві таблиці пар: напрацьовані історією рядки йдуть слідом за нею
     * (лічильники не мають вказувати на видалені замовлення), а додані
     * адміністратором наперед (orders_count = 0) — інтент, не історія —
     * переживають скидання. Рішення власника, 2026-08-17.
     */
    public function test_reset_deletes_earned_pairs_and_keeps_hand_added_ones(): void
    {
        $signatory = UniversityRef::factory()->create();
        $earned = Department::factory()->create(['name' => 'Департамент реклами']);
        $handAdded = Department::factory()->create(['name' => 'КЖУР']);

        SignatoryCostCenter::factory()->create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $earned->id,
            'orders_count'      => 40,
        ]);
        SignatoryCostCenter::factory()->create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $handAdded->id,
            'orders_count'      => 0,
        ]);

        CostCenterInitiator::create([
            'department_id' => $earned->id,
            'name'          => 'Іванова О.В.',
            'name_key'      => 'іванова о.в.',
            'orders_count'  => 7,
            'last_used_at'  => now(),
        ]);
        CostCenterInitiator::create([
            'department_id' => $handAdded->id,
            'name'          => 'Додана Наперед',
            'name_key'      => 'додана наперед',
            'orders_count'  => 0,
        ]);

        $this->artisan('production:reset', ['--cash' => '5000'])
            ->expectsConfirmation('Are you absolutely sure? Type YES to proceed', 'yes')
            ->expectsConfirmation('LAST CHANCE: This cannot be undone. Continue?', 'yes')
            ->assertSuccessful();

        $this->assertSame(
            [$handAdded->id],
            SignatoryCostCenter::pluck('department_id')->all(),
            'Only the hand-added signatory pair must survive the reset.',
        );
        $this->assertSame(
            ['Додана Наперед'],
            CostCenterInitiator::pluck('name')->all(),
            'Only the hand-added initiator must survive the reset.',
        );
    }
}
