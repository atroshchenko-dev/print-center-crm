<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bulk receipt command — the last warehouse entry point with no tests.
 *
 * It reads a hand-written JSON file and writes stock, which makes the file the
 * least trustworthy input the system has and the command the one place that has
 * to distrust it. Two things it did not do: check the rows before writing any of
 * them, and check that a quantity is a quantity.
 */
class BulkReceiptCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create(['role' => 'admin', 'permissions' => User::permissionKeys()]);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink(base_path($path));
        }

        parent::tearDown();
    }

    private function item(string $name): InventoryItem
    {
        return InventoryItem::factory()->create([
            'name'             => $name,
            'current_quantity' => 0,
            'avg_cost'         => 0,
        ]);
    }

    /** @param array<mixed> $items */
    private function file(array $items): string
    {
        $relative = 'storage/app/bulk-receipt-'.uniqid().'.json';
        file_put_contents(base_path($relative), json_encode([
            'notes' => 'Тестове оприбуткування',
            'items' => $items,
        ]));

        $this->written[] = $relative;

        return $relative;
    }

    public function test_a_well_formed_file_is_received(): void
    {
        $paper = $this->item('Папір А4');
        $toner = $this->item('Тонер чорний');

        $this->artisan('inventory:bulk-receipt', ['file' => $this->file([
            ['Папір А4', 100, 2.50],
            ['Тонер чорний', 4, 900.00],
        ])])->assertSuccessful();

        $this->assertEqualsWithDelta(100, $paper->fresh()->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(2.50, $paper->fresh()->avg_cost, 0.0001);
        $this->assertEqualsWithDelta(4, $toner->fresh()->current_quantity, 0.0001);
        $this->assertSame(2, InventoryMovement::count());
    }

    /**
     * A row short of a field used to raise its error from inside the loop —
     * after every row above it had already been received. The operator saw a
     * stack trace and a warehouse in a state nobody had asked for.
     */
    public function test_a_short_row_stops_the_file_before_anything_is_written(): void
    {
        $paper = $this->item('Папір А4');

        $this->artisan('inventory:bulk-receipt', ['file' => $this->file([
            ['Папір А4', 100, 2.50],
            ['Тонер чорний'],
        ])])->assertFailed();

        $this->assertEqualsWithDelta(
            0,
            $paper->fresh()->current_quantity,
            0.0001,
            'The row above the broken one was received anyway.',
        );
        $this->assertSame(0, InventoryMovement::count());
    }

    /**
     * receiveStock() takes the quantity as given. A negative one subtracts
     * stock while filing a movement of type `in`, at a unit cost it reports as
     * zero — so the warehouse falls and the running average falls with it,
     * under a record that reads as a delivery.
     */
    public function test_a_negative_quantity_is_refused(): void
    {
        $paper = InventoryItem::factory()->create([
            'name'             => 'Папір А4',
            'current_quantity' => 500,
            'avg_cost'         => 2.00,
        ]);

        $this->artisan('inventory:bulk-receipt', [
            'file'    => $this->file([['Папір А4', -100, 2.50]]),
            '--force' => true,
        ])->assertFailed();

        $this->assertEqualsWithDelta(500, $paper->fresh()->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(2.00, $paper->fresh()->avg_cost, 0.0001);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_a_zero_quantity_is_refused(): void
    {
        $this->item('Папір А4');

        $this->artisan('inventory:bulk-receipt', ['file' => $this->file([
            ['Папір А4', 0, 2.50],
        ])])->assertFailed();

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_a_negative_unit_cost_is_refused(): void
    {
        $this->item('Папір А4');

        $this->artisan('inventory:bulk-receipt', ['file' => $this->file([
            ['Папір А4', 10, -2.50],
        ])])->assertFailed();

        $this->assertSame(0, InventoryMovement::count());
    }

    /**
     * Validation catches the malformed file; the transaction catches everything
     * else — a check constraint, a failed write, a row the validator let past.
     * Rows already received when the run dies have to go back with it.
     *
     * The failure is raised with a neutral message on purpose. Laravel reads
     * exception *text* for concurrency keywords, and on a nested transaction it
     * rethrows those without touching the savepoint — a real deadlock has
     * already killed the whole transaction, so there is nothing to roll back
     * to. Say "deadlock detected" here and the fake is handled as the real
     * thing, and the test passes or fails for a reason that has nothing to do
     * with the command.
     */
    public function test_a_failure_partway_through_takes_the_rows_above_it_back(): void
    {
        $paper = $this->item('Папір А4');
        $this->item('Тонер чорний');

        $this->app->bind(InventoryService::class, fn () => new class extends InventoryService
        {
            private int $calls = 0;

            public function receiveStock(InventoryItem $item, float $quantity, float $totalCost, User $user, ?string $notes = null): InventoryMovement
            {
                if (++$this->calls > 1) {
                    throw new \RuntimeException('receipt refused by the database');
                }

                return parent::receiveStock($item, $quantity, $totalCost, $user, $notes);
            }
        });

        try {
            $this->artisan('inventory:bulk-receipt', ['file' => $this->file([
                ['Папір А4', 100, 2.50],
                ['Тонер чорний', 4, 900.00],
            ])])->run();
            $this->fail('The run was expected to die on the second row.');
        } catch (\RuntimeException) {
            // The failure is the point; what it left behind is what is under test.
        }

        $this->assertEqualsWithDelta(
            0,
            $paper->fresh()->current_quantity,
            0.0001,
            'The first row stayed received after the run died on the second.',
        );
        $this->assertSame(0, InventoryMovement::count());
    }

    /**
     * The skips are business rules, not file errors: an unknown name or an item
     * that already holds stock is reported and stepped over, and the rest of the
     * file still lands.
     */
    public function test_unknown_names_and_stocked_items_are_skipped_not_fatal(): void
    {
        $paper = $this->item('Папір А4');
        $stocked = InventoryItem::factory()->create([
            'name'             => 'Тонер чорний',
            'current_quantity' => 3,
            'avg_cost'         => 900.00,
        ]);

        $this->artisan('inventory:bulk-receipt', ['file' => $this->file([
            ['Немає такого', 10, 1.00],
            ['Тонер чорний', 4, 900.00],
            ['Папір А4', 100, 2.50],
        ])])->assertSuccessful();

        $this->assertEqualsWithDelta(100, $paper->fresh()->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(3, $stocked->fresh()->current_quantity, 0.0001);
        $this->assertSame(1, InventoryMovement::count());
    }
}
