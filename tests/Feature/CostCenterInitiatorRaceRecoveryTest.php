<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CostCenterInitiator;
use App\Models\Department;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Гілка відновлення в CostCenterInitiator::remember() після програної гонки:
 * той, хто дістав UniqueConstraintViolationException, мусить дорахувати
 * використання на рядок переможця, а не загубити його.
 *
 * RefreshDatabase тут не годиться навмисно: переможець, вставлений тим самим
 * з'єднанням усередині savepoint, відкочується разом із ним, і catch знаходить
 * порожнечу. Справжня гонка — інше з'єднання з власним комітом; саме так вона
 * тут і відтворена. Звідси DatabaseTruncation і ручне прибирання в tearDown —
 * рядки цього тесту закомічені по-справжньому й пережили б клас, лягаючи під
 * ноги транзакційним суїтам далі за абеткою.
 */
class CostCenterInitiatorRaceRecoveryTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * Unscoped `DatabaseTruncation` truncates every table in the database at
     * setUp — including tables a migration seeds directly, such as
     * `inventory_categories` (`add_consumables_category.php`). Nothing
     * restores a migration-seeded row (there is no seeder for it to rerun),
     * so every later test in the same process that depends on it — the
     * whole of `InventoryStocktakeCommandTest` — failed once this file ran
     * before them.
     *
     * Scoped to exactly the two tables this test writes to. Neither
     * `departments` nor `cost_center_initiators` is seeded by a migration
     * (checked: `grep -rl "->insert(" database/migrations/` names four
     * files, none of them either table), so narrowing here loses nothing
     * this test needs. Postgres truncates with `RESTART IDENTITY CASCADE`
     * by default (`PostgresGrammar::compileTruncate()`), though, so
     * truncating `departments` also empties every table with a foreign key
     * into it — `department_limits` and `signatory_cost_centers` here —
     * even though neither is in this list; harmless for a test that writes
     * to neither, but the list is not a complete account of what actually
     * gets truncated.
     *
     * @var array<int, string>
     */
    protected array $tablesToTruncate = ['cost_center_initiators', 'departments'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.pgsql_rival' => config('database.connections.pgsql'),
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('cost_center_initiators')->delete();
        DB::table('departments')->delete();
        DB::purge('pgsql_rival');

        parent::tearDown();
    }

    public function test_the_loser_counts_the_use_onto_the_winner_row(): void
    {
        $department = Department::factory()->create(['name' => 'КЖУР']);

        // Суперник вставляє той самий ключ у вікні між read і write —
        // з іншого з'єднання, з власним комітом, як у житті.
        CostCenterInitiator::creating(function () use ($department): void {
            DB::connection('pgsql_rival')->table('cost_center_initiators')->insert([
                'department_id' => $department->id,
                'name'          => 'Іванова О.В.',
                'name_key'      => 'іванова о.в.',
                'orders_count'  => 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        });

        // Зовнішня транзакція — як транзакція замовлення в проді: save()
        // всередині remember() стає savepoint'ом саме під нею.
        DB::transaction(function (): void {
            CostCenterInitiator::remember('КЖУР', 'Іванова О.В.');
        });

        $this->assertSame(1, CostCenterInitiator::count());

        $winner = CostCenterInitiator::first();
        $this->assertSame('Іванова О.В.', $winner->name);
        $this->assertSame(2, $winner->orders_count, 'The lost use must land on the winner row.');
        $this->assertNotNull($winner->last_used_at);
    }
}
