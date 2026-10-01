<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The stocktake command, against the file it is handed.
 *
 * The file is written by hand from a paper count, which makes it the least
 * trustworthy input the warehouse has — and unlike a receipt, a stocktake
 * overwrites a balance instead of adding to it. A row it cannot resolve is a
 * position nobody counted, so the whole file waits rather than landing without it.
 */
class InventoryStocktakeCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $written = [];

    private InventoryCategory $consumables;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        // Without a token TelegramService::send() returns before it reaches the
        // wire, and every Http::assertSent below would be asserting on silence.
        Config::set('services.telegram.bot_token', 'test-token-123');
        Config::set('services.telegram.chat_id', '12345678');
        Cache::forget('app:settings');

        User::factory()->create(['role' => 'admin', 'permissions' => User::permissionKeys()]);

        // The consumables category is created by a data migration
        // (2026_06_30_170000_add_consumables_category), so RefreshDatabase has
        // it before the first test runs.
        $this->consumables = InventoryCategory::where('name', InventoryCategory::CONSUMABLES_SLUG)->firstOrFail();
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink(base_path($path));
        }

        parent::tearDown();
    }

    private function toner(string $name, float $full = 0, float $empty = 0): InventoryItem
    {
        return InventoryItem::factory()->create([
            'inventory_category_id' => $this->consumables->id,
            'name'                  => $name,
            'current_quantity'      => $full,
            'empty_quantity'        => $empty,
            'avg_cost'              => 900.00,
        ]);
    }

    /** @param array<mixed> $items */
    private function file(array $items): string
    {
        $relative = 'storage/app/stocktake-'.uniqid().'.json';
        file_put_contents(base_path($relative), json_encode([
            'notes' => 'Тестова інвентаризація',
            'items' => $items,
        ]));

        $this->written[] = $relative;

        return $relative;
    }

    public function test_a_well_formed_file_states_both_balances(): void
    {
        $konica = $this->toner('Тонер Konica', full: 2, empty: 0);
        $kyocera = $this->toner('Тонер Kyocera', full: 0, empty: 5);

        $this->artisan('inventory:stocktake', ['file' => $this->file([
            ['Тонер Konica', 1, 3, 1],
            ['Тонер Kyocera', 1, 1, 1],
        ])])->assertSuccessful();

        // «в принтері» counts with the full ones: the tube in the machine is filled.
        $this->assertEqualsWithDelta(4, $konica->fresh()->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(1, $konica->fresh()->empty_quantity, 0.0001);
        $this->assertEqualsWithDelta(2, $kyocera->fresh()->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(1, $kyocera->fresh()->empty_quantity, 0.0001);
        $this->assertSame(2, InventoryMovement::count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $konica = $this->toner('Тонер Konica', full: 2, empty: 0);

        $this->artisan('inventory:stocktake', [
            'file'      => $this->file([['Тонер Konica', 1, 3, 1]]),
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertEqualsWithDelta(2, $konica->fresh()->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(0, $konica->fresh()->empty_quantity, 0.0001);
        $this->assertSame(0, InventoryMovement::count());
        Http::assertNothingSent();
    }

    public function test_an_unknown_name_stops_the_whole_file(): void
    {
        $konica = $this->toner('Тонер Konica', full: 2, empty: 0);

        $this->artisan('inventory:stocktake', ['file' => $this->file([
            ['Тонер Konica', 1, 3, 1],
            ['Тонер якого немає', 1, 1, 0],
        ])])->assertFailed();

        $this->assertEqualsWithDelta(
            2,
            $konica->fresh()->current_quantity,
            0.0001,
            'The row above the unresolvable one was applied anyway.',
        );
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_an_item_outside_the_consumables_category_is_refused(): void
    {
        $paper = InventoryItem::factory()->create([
            'name'             => 'Папір А4',
            'current_quantity' => 500,
        ]);

        $this->artisan('inventory:stocktake', ['file' => $this->file([
            ['Папір А4', 0, 300, 0],
        ])])->assertFailed();

        $this->assertEqualsWithDelta(500, $paper->fresh()->current_quantity, 0.0001);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_a_negative_count_is_refused(): void
    {
        $this->toner('Тонер Konica', full: 2);

        $this->artisan('inventory:stocktake', ['file' => $this->file([
            ['Тонер Konica', 1, -3, 1],
        ])])->assertFailed();

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_a_duplicated_name_is_refused(): void
    {
        $this->toner('Тонер Konica', full: 2);

        $this->artisan('inventory:stocktake', ['file' => $this->file([
            ['Тонер Konica', 1, 3, 1],
            ['Тонер Konica', 0, 1, 0],
        ])])->assertFailed();

        $this->assertSame(0, InventoryMovement::count());
    }

    /**
     * A position of the category the file never mentions keeps its balance —
     * and gets said out loud, because the alternative is a line nobody counted
     * silently passing for counted.
     */
    public function test_a_position_missing_from_the_file_is_left_alone_and_reported(): void
    {
        $this->toner('Тонер Konica', full: 2);
        $film = $this->toner('Майстер-плівка', full: 7);

        $this->artisan('inventory:stocktake', ['file' => $this->file([
            ['Тонер Konica', 1, 3, 1],
        ])])
            ->expectsOutputToContain('Майстер-плівка')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(7, $film->fresh()->current_quantity, 0.0001);
    }

    public function test_a_count_that_matches_the_books_is_reported_as_unchanged(): void
    {
        $this->toner('Тонер Konica', full: 4, empty: 1);

        $this->artisan('inventory:stocktake', ['file' => $this->file([
            ['Тонер Konica', 1, 3, 1],
        ])])
            ->expectsOutputToContain('без змін')
            ->assertSuccessful();

        $this->assertSame(0, InventoryMovement::count());
        Http::assertNothingSent();
    }

    public function test_the_summary_reaches_telegram(): void
    {
        $this->toner('Тонер Konica', full: 2, empty: 0);

        $this->artisan('inventory:stocktake', ['file' => $this->file([
            ['Тонер Konica', 1, 3, 1],
        ])])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request['text'], 'Інвентаризація')
            && str_contains($request['text'], 'Тонер Konica'));
    }
}
