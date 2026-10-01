<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Services\ReferenceDataService;
use App\Support\KyivClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time production reset command.
 *
 * Cleans ALL test orders, ledger entries, audit logs, counter readings,
 * inventory movements, shifts, and pair-table rows with recorded orders (unused rows kept).
 * Then sets actual counter readings and cash balance for production start.
 *
 * DANGER: This is destructive and irreversible. Must confirm interactively.
 */
class ProductionResetCommand extends Command
{
    protected $signature = 'production:reset
        {--cash=7600 : Actual cash balance in register (UAH)}';

    protected $description = 'Reset production data: clear test orders, set actual counters & cash';

    /**
     * Actual counter readings per equipment (as of 2026-05-04 morning).
     */
    private array $counterReadings = [
        'Konica Minolta bizhub 283' => 2442646,
        'Kyocera ECOSYS M4125idn'   => 120240,
        'Develop ineo+ 251i'        => 20221,
        'Develop ineo+ 220'         => 558891,
        // Ricoh DD4450 (RISO) — no counter
    ];

    public function handle(): int
    {
        $this->error('╔══════════════════════════════════════════════╗');
        $this->error('║  ⚠  PRODUCTION DATA RESET — DESTRUCTIVE!    ║');
        $this->error('╚══════════════════════════════════════════════╝');
        $this->newLine();

        $cash = (float) $this->option('cash');

        $this->info('Equipment counter readings:');
        $rows = [];
        foreach ($this->counterReadings as $name => $value) {
            $rows[] = [$name, number_format($value)];
        }
        $rows[] = ['Ricoh DD4450 (RISO)', 'без лічильника'];
        $rows[] = ['─────────', '─────────'];
        $rows[] = ['💰 Каса', number_format($cash, 2).' грн'];
        $this->table(['Equipment', 'Counter'], $rows);

        $this->newLine();
        $this->warn('This will DELETE ALL:');
        $this->line('  • Orders & order items');
        $this->line('  • Ledger transactions');
        $this->line('  • Audit logs');
        $this->line('  • Counter readings');
        $this->line('  • Inventory movements');
        $this->line('  • Shifts');
        $this->line('  • Signatory & initiator pairs with recorded orders (unused rows kept)');
        $this->newLine();

        if (! $this->confirm('Are you absolutely sure? Type YES to proceed', false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        if (! $this->confirm('LAST CHANCE: This cannot be undone. Continue?', false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($cash) {
            // ── Step 1: Disable user immutability triggers ──
            // (USER = only user-defined triggers, not FK system triggers — no superuser needed)
            $this->info('🔓 Disabling immutability triggers...');
            DB::statement('ALTER TABLE ledger_transactions DISABLE TRIGGER USER');
            DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER USER');
            DB::statement('ALTER TABLE inventory_movements DISABLE TRIGGER USER');
            DB::statement('ALTER TABLE shift_counter_readings DISABLE TRIGGER USER');

            // ── Step 2: Clear all transactional data ──
            $this->info('🗑  Clearing transactional data...');

            // Order items first (FK to orders)
            $orderItems = DB::table('order_items')->count();
            DB::table('order_items')->delete();
            $this->line("   • order_items: {$orderItems} deleted");

            // Orders
            $orders = DB::table('orders')->count();
            DB::table('orders')->delete();
            $this->line("   • orders: {$orders} deleted");

            // Ledger
            $ledger = DB::table('ledger_transactions')->count();
            DB::table('ledger_transactions')->delete();
            $this->line("   • ledger_transactions: {$ledger} deleted");

            // Audit logs
            $audit = DB::table('audit_logs')->count();
            DB::table('audit_logs')->delete();
            $this->line("   • audit_logs: {$audit} deleted");

            // Counter readings
            $readings = DB::table('shift_counter_readings')->count();
            DB::table('shift_counter_readings')->delete();
            $this->line("   • shift_counter_readings: {$readings} deleted");

            // Inventory movements
            $movements = DB::table('inventory_movements')->count();
            DB::table('inventory_movements')->delete();
            $this->line("   • inventory_movements: {$movements} deleted");

            // Shifts
            $shifts = DB::table('shifts')->count();
            DB::table('shifts')->delete();
            $this->line("   • shifts: {$shifts} deleted");

            // Pair tables: rows earned by the deleted history go with it —
            // their counters would otherwise point at orders that no longer
            // exist, and the backfill skips (not zeroes) a pair absent from
            // history. Hand-added rows (orders_count = 0) are admin intent,
            // not history, and stay. Owner's decision, 2026-08-17.
            $signatoryPairs = DB::table('signatory_cost_centers')->where('orders_count', '>', 0)->delete();
            $this->line("   • signatory_cost_centers: {$signatoryPairs} deleted (unused rows kept)");

            $initiatorPairs = DB::table('cost_center_initiators')->where('orders_count', '>', 0)->delete();
            $this->line("   • cost_center_initiators: {$initiatorPairs} deleted (unused rows kept)");

            // ── Step 3: Reset sequences ──
            $this->info('🔢 Resetting ID sequences...');
            DB::statement('ALTER SEQUENCE orders_id_seq RESTART WITH 1');
            DB::statement('ALTER SEQUENCE order_items_id_seq RESTART WITH 1');
            DB::statement('ALTER SEQUENCE shifts_id_seq RESTART WITH 1');
            DB::statement('ALTER SEQUENCE ledger_transactions_id_seq RESTART WITH 1');
            DB::statement('ALTER SEQUENCE audit_logs_id_seq RESTART WITH 1');
            DB::statement('ALTER SEQUENCE shift_counter_readings_id_seq RESTART WITH 1');
            DB::statement('ALTER SEQUENCE inventory_movements_id_seq RESTART WITH 1');

            // ── Step 4: Set actual counter values on equipment ──
            $this->info('📊 Setting actual counter readings on equipment...');
            foreach ($this->counterReadings as $name => $value) {
                $updated = DB::table('equipment')
                    ->where('name', $name)
                    ->whereNull('deleted_at')
                    ->update(['initial_counter' => $value]);
                $this->line("   • {$name}: initial_counter = ".number_format($value).($updated ? ' ✅' : ' ⚠ not found'));
            }

            // Disable counter on RISO (no physical counter)
            DB::table('equipment')
                ->where('name', 'Ricoh DD4450')
                ->whereNull('deleted_at')
                ->update(['has_counter' => false]);
            $this->line('   • Ricoh DD4450: has_counter = false ✅');

            // ── Step 5: Create seed shift for cash balance ──
            // The shift open form reads cash_start from last closed shift's cash_actual.
            // Without a seed shift, it defaults to 0.
            $this->info('💰 Creating seed shift with cash balance...');
            $adminId = DB::table('users')->where('role', UserRole::Admin->value)->value('id') ?? 1;
            $yesterday = now('Europe/Kyiv')->subDay()->toDateString();
            DB::table('shifts')->insert([
                'date'                => $yesterday,
                'opened_by'           => $adminId,
                'closed_by'           => $adminId,
                'status'              => ShiftStatus::Closed->value,
                'cash_start'          => $cash,
                'cash_calculated'     => $cash,
                'cash_actual'         => $cash,
                'auto_closed'         => false,
                'settlement_required' => false,
                'settlement_done'     => true,
                // Kyiv wall clocks converted to the UTC instants they mean —
                // inserted raw they read back 2-3 hours late (R7-2, I-3).
                'opened_at'  => KyivClock::instantFromLocal("{$yesterday} 09:00"),
                'closed_at'  => KyivClock::instantFromLocal("{$yesterday} 18:00"),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->line("   • Seed shift ({$yesterday}): cash_actual = ".number_format($cash, 2).' грн ✅');

            // ── Step 5: Re-enable immutability triggers ──
            $this->info('🔒 Re-enabling immutability triggers...');
            DB::statement('ALTER TABLE ledger_transactions ENABLE TRIGGER USER');
            DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER USER');
            DB::statement('ALTER TABLE inventory_movements ENABLE TRIGGER USER');
            DB::statement('ALTER TABLE shift_counter_readings ENABLE TRIGGER USER');
        });

        // ── Summary ──
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════╗');
        $this->info('║  ✅  PRODUCTION RESET COMPLETE               ║');
        $this->info('╚══════════════════════════════════════════════╝');
        $this->newLine();
        $this->warn('Далі:');
        $this->line("  1. Відкрити зміну → каса: {$cash} грн");
        $this->line('  2. Ввести ранкові показники (підтягнуться автоматично)');
        $this->line('  3. Перевірити залишки на Складі');
        $this->newLine();

        // Flush caches
        app(ReferenceDataService::class)->flush();
        $this->info('🔄 Reference data cache flushed.');

        return self::SUCCESS;
    }
}
