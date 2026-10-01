<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderType;
use App\Models\AuditLog;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Models\SignatoryGroup;
use App\Models\UniversityRef;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ImportRetroOrdersTest — Tests for the retro:import batch command.
 *
 * Validates: JSON loading, consecutive grouping, signatory fuzzy matching,
 * paper code mapping, color/BW service detection, group field splitting,
 * dry-run mode, and customer paper/no_paper edge cases.
 */
class ImportRetroOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        // Create materials
        Material::factory()->create(['counter_type' => 'bw', 'click_cost' => 0.12, 'is_active' => true]);
        Material::factory()->create(['counter_type' => 'color', 'click_cost' => 0.60, 'is_active' => true]);

        // Create BW service with constructor options
        $printCategory = ServiceCategory::factory()->create(['name' => 'Друк']);

        $bwService = Service::factory()->create([
            'name'                => 'Чорно-білий друк',
            'type'                => 'constructor',
            'service_category_id' => $printCategory->id,
            'is_active'           => true,
        ]);

        $colorService = Service::factory()->create([
            'name'                => 'Кольоровий друк',
            'type'                => 'constructor',
            'service_category_id' => $printCategory->id,
            'is_active'           => true,
        ]);

        // Create paper inventory
        $paperCategory = InventoryCategory::factory()->create(['name' => 'Папір']);
        $paper80 = InventoryItem::factory()->create([
            'name'                  => 'Папір А4 80 г/м²',
            'inventory_category_id' => $paperCategory->id,
            'avg_cost'              => 0.50,
            'current_quantity'      => 1000,
            'is_active'             => true,
        ]);

        // Create constructor options for BW and Color services
        foreach ([$bwService, $colorService] as $service) {
            $formatGroup = ServiceParameterGroup::factory()->create([
                'service_id'  => $service->id,
                'name'        => 'Формат',
                'is_required' => true,
            ]);

            $formatA4 = ServiceParameterOption::factory()->create([
                'group_id'     => $formatGroup->id,
                'name'         => 'А4',
                'price_markup' => 0,
                'cost_markup'  => 0,
                'is_active'    => true,
            ]);

            $paperGroup = ServiceParameterGroup::factory()->create([
                'service_id'  => $service->id,
                'name'        => 'Тип паперу',
                'is_required' => true,
            ]);

            $paperOpt = ServiceParameterOption::factory()->create([
                'group_id'          => $paperGroup->id,
                'name'              => 'А4: Папір 80 г/м²',
                'price_markup'      => 1.00,
                'cost_markup'       => 0.50,
                'inventory_item_id' => $paper80->id,
                'inventory_qty'     => 1,
                'depends_on'        => ['option_ids' => [$formatA4->id]],
                'is_active'         => true,
            ]);

            $sidesGroup = ServiceParameterGroup::factory()->create([
                'service_id'  => $service->id,
                'name'        => 'Сторонність',
                'is_required' => true,
            ]);

            if ($service->name === 'Чорно-білий друк') {
                ServiceParameterOption::factory()->create([
                    'group_id'     => $sidesGroup->id,
                    'name'         => 'А4: 1+0',
                    'price_markup' => 1.20,
                    'cost_markup'  => 0.12,
                    'depends_on'   => ['option_ids' => [$paperOpt->id]],
                    'is_active'    => true,
                ]);
            } else {
                ServiceParameterOption::factory()->create([
                    'group_id'     => $sidesGroup->id,
                    'name'         => 'А4: Папір 80 г/м²: 4+0',
                    'price_markup' => 3.00,
                    'cost_markup'  => 0.60,
                    'depends_on'   => ['option_ids' => [$paperOpt->id]],
                    'is_active'    => true,
                ]);
            }
        }

        // Create signatory
        $group = SignatoryGroup::factory()->create();
        UniversityRef::factory()->create([
            'full_name'          => 'Карприна Олена',
            'signatory_group_id' => $group->id,
            'is_active'          => true,
        ]);
    }

    public function test_dry_run_validates_without_creating_orders(): void
    {
        $json = $this->createJsonFile([
            ['date' => '04.08.2025', 'signatory' => 'Карприна', 'department' => 'БШК', 'format' => 'А4', 'quantity' => 1, 'paper' => '80', 'color_mode' => '1+0'],
        ]);

        $this->artisan('retro:import', ['--file' => $json, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertEquals(0, Order::where('is_backdated', true)->count());
    }

    public function test_imports_single_order(): void
    {
        $json = $this->createJsonFile([
            ['date' => '04.08.2025', 'signatory' => 'Карприна', 'department' => 'БШК', 'format' => 'А4', 'quantity' => 3, 'paper' => '80', 'color_mode' => '1+0'],
        ]);

        $this->artisan('retro:import', ['--file' => $json, '--user' => $this->admin->id])
            ->assertExitCode(0);

        $orders = Order::where('is_backdated', true)->get();
        $this->assertCount(1, $orders);

        $order = $orders->first();
        $this->assertTrue($order->is_backdated);
        $this->assertEquals(OrderType::Internal, $order->type);
        $this->assertNull($order->shift_id);
        $this->assertStringContainsString('Карприна', $order->authorized_person);
        $this->assertEquals('БШК', $order->cost_center);
    }

    public function test_groups_consecutive_rows_into_one_order(): void
    {
        $json = $this->createJsonFile([
            ['date' => '04.08.2025', 'signatory' => 'Карприна', 'department' => 'БШК', 'format' => 'А4', 'quantity' => 1, 'paper' => '80', 'color_mode' => '1+0'],
            ['date' => '04.08.2025', 'signatory' => 'Карприна', 'department' => 'БШК', 'format' => 'А4', 'quantity' => 2, 'paper' => '80', 'color_mode' => '1+0'],
        ]);

        $this->artisan('retro:import', ['--file' => $json, '--user' => $this->admin->id])
            ->assertExitCode(0);

        // Same date+signatory+department = 1 order with 2 items
        $orders = Order::where('is_backdated', true)->get();
        $this->assertCount(1, $orders);
        $this->assertCount(2, $orders->first()->items);
    }

    public function test_different_signatory_creates_separate_orders(): void
    {
        // Create second signatory
        $group = SignatoryGroup::first();
        UniversityRef::factory()->create([
            'full_name'          => 'Накченко Ірина',
            'signatory_group_id' => $group->id,
            'is_active'          => true,
        ]);

        $json = $this->createJsonFile([
            ['date' => '04.08.2025', 'signatory' => 'Карприна', 'department' => 'БШК', 'format' => 'А4', 'quantity' => 1, 'paper' => '80', 'color_mode' => '1+0'],
            ['date' => '04.08.2025', 'signatory' => 'Накченко', 'department' => 'БШК', 'format' => 'А4', 'quantity' => 1, 'paper' => '80', 'color_mode' => '1+0'],
        ]);

        $this->artisan('retro:import', ['--file' => $json, '--user' => $this->admin->id])
            ->assertExitCode(0);

        // Different signatories = 2 separate orders
        $this->assertEquals(2, Order::where('is_backdated', true)->count());
    }

    public function test_group_field_forces_separate_orders(): void
    {
        $json = $this->createJsonFile([
            ['date' => '04.08.2025', 'signatory' => 'Карприна', 'department' => 'БШК', 'format' => 'А4', 'quantity' => 1, 'paper' => '80', 'color_mode' => '1+0'],
            ['date' => '04.08.2025', 'signatory' => 'Карприна', 'department' => 'БШК', 'format' => 'А4', 'quantity' => 1, 'paper' => '80', 'color_mode' => '1+0', 'group' => 'envelopes'],
        ]);

        $this->artisan('retro:import', ['--file' => $json, '--user' => $this->admin->id])
            ->assertExitCode(0);

        // group field forces split → 2 orders
        $this->assertEquals(2, Order::where('is_backdated', true)->count());
    }

    public function test_color_mode_selects_correct_service(): void
    {
        $json = $this->createJsonFile([
            ['date' => '04.08.2025', 'signatory' => 'Карприна', 'department' => 'БШК', 'format' => 'А4', 'quantity' => 1, 'paper' => '80', 'color_mode' => '4+0'],
        ]);

        $this->artisan('retro:import', ['--file' => $json, '--user' => $this->admin->id])
            ->assertExitCode(0);

        $order = Order::where('is_backdated', true)->first();
        $item = $order->items->first();
        $this->assertEquals('Кольоровий друк', $item->service_name);
    }

    public function test_fails_with_missing_file(): void
    {
        $this->artisan('retro:import', ['--file' => '/nonexistent/path.json'])
            ->assertExitCode(1);
    }

    public function test_fails_with_unknown_paper_code(): void
    {
        $json = $this->createJsonFile([
            ['date' => '04.08.2025', 'signatory' => 'Карприна', 'department' => 'БШК', 'format' => 'А4', 'quantity' => 1, 'paper' => '999', 'color_mode' => '1+0'],
        ]);

        $this->artisan('retro:import', ['--file' => $json, '--user' => $this->admin->id])
            ->assertExitCode(1);
    }

    public function test_sets_created_at_to_order_date(): void
    {
        $json = $this->createJsonFile([
            ['date' => '15.08.2025', 'signatory' => 'Карприна', 'department' => 'БШК', 'format' => 'А4', 'quantity' => 1, 'paper' => '80', 'color_mode' => '1+0'],
        ]);

        $this->artisan('retro:import', ['--file' => $json, '--user' => $this->admin->id])
            ->assertExitCode(0);

        $order = Order::where('is_backdated', true)->first();
        $this->assertEquals(8, $order->created_at->month);
        $this->assertEquals(2025, $order->created_at->year);
    }

    /**
     * The category rule reaches the import too — owner's decision, 2026-08-01.
     *
     * The decision is about the retro **module**, and this command builds the
     * same orders as `BackdatedOrderController` with different code. A month
     * arriving as a JSON file therefore gets the same note as a month typed in
     * by hand; otherwise the rule would hold only where somebody happened to be
     * watching. It warns and records, and the import still succeeds — what it
     * describes already happened.
     */
    public function test_a_service_outside_the_signatory_categories_is_recorded(): void
    {
        // The group is allowed one category, and it is not the printing one.
        $group = SignatoryGroup::first();
        $group->categories()->sync([ServiceCategory::factory()->create(['name' => 'Візитівки'])->id]);

        $json = $this->createJsonFile([
            ['date' => '04.08.2025', 'signatory' => 'Карприна', 'department' => 'БШК', 'format' => 'А4', 'quantity' => 1, 'paper' => '80', 'color_mode' => '1+0'],
        ]);

        $this->artisan('retro:import', ['--file' => $json, '--user' => $this->admin->id])
            ->expectsOutputToContain('поза дозволеними категоріями')
            ->assertExitCode(0);

        $this->assertSame(1, Order::where('is_backdated', true)->count(), 'the month is still recorded');

        $entry = AuditLog::where('event_type', 'category_not_allowed')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame('retro_import', $entry->meta['source'] ?? null);
    }

    /** A signatory whose group allows the printing category imports in silence. */
    public function test_an_import_inside_the_categories_says_nothing(): void
    {
        $group = SignatoryGroup::first();
        $group->categories()->sync([ServiceCategory::where('name', 'Друк')->firstOrFail()->id]);

        $json = $this->createJsonFile([
            ['date' => '04.08.2025', 'signatory' => 'Карприна', 'department' => 'БШК', 'format' => 'А4', 'quantity' => 1, 'paper' => '80', 'color_mode' => '1+0'],
        ]);

        $this->artisan('retro:import', ['--file' => $json, '--user' => $this->admin->id])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['event_type' => 'category_not_allowed']);
    }

    // ─── Helpers ─────────────────────────────────────────

    private function createJsonFile(array $data): string
    {
        $path = storage_path('app/test-retro-import.json');
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $path;
    }
}
