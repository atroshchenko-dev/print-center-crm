<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CostCenterInitiator;
use App\Models\Department;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Підказки мають бути корисними з першого дня, а не через півроку.
 * Головна вимога — ідемпотентність: команду запустять двічі.
 */
class CostCenterInitiatorBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    public function test_it_counts_the_pairs_history_holds(): void
    {
        Department::factory()->create(['name' => 'Кафедра туризму']);

        Order::factory()->internal()->count(3)->create([
            'cost_center' => 'Кафедра туризму',
            'initiator'   => 'Іванова О.В.',
        ]);
        CostCenterInitiator::query()->delete();

        $this->artisan('cost-centers:backfill-initiators')->assertSuccessful();

        $this->assertSame(1, CostCenterInitiator::count());
        $this->assertSame(3, CostCenterInitiator::first()->orders_count);
    }

    public function test_running_it_twice_does_not_double_the_counters(): void
    {
        Department::factory()->create(['name' => 'КЖУР']);

        Order::factory()->internal()->count(2)->create([
            'cost_center' => 'КЖУР',
            'initiator'   => 'Петренко І.І.',
        ]);
        CostCenterInitiator::query()->delete();

        $this->artisan('cost-centers:backfill-initiators')->assertSuccessful();
        $this->artisan('cost-centers:backfill-initiators')->assertSuccessful();

        $this->assertSame(2, CostCenterInitiator::first()->orders_count);
    }

    public function test_it_folds_case_and_spacing_into_one_pair(): void
    {
        Department::factory()->create(['name' => 'ДКДЗ']);

        Order::factory()->internal()->create([
            'cost_center' => 'ДКДЗ',
            'initiator'   => 'Іванова О.В.',
        ]);
        Order::factory()->internal()->create([
            'cost_center' => ' дкдз',
            'initiator'   => 'іванова о.в. ',
        ]);
        CostCenterInitiator::query()->delete();

        $this->artisan('cost-centers:backfill-initiators')->assertSuccessful();

        $this->assertSame(1, CostCenterInitiator::count());
        $this->assertSame(2, CostCenterInitiator::first()->orders_count);
    }

    /** При рівних правах на написання виграє свіжіше замовлення. */
    public function test_the_newest_spelling_wins(): void
    {
        Department::factory()->create(['name' => 'КЛБ']);

        Order::factory()->internal()->create([
            'cost_center' => 'КЛБ',
            'initiator'   => 'іванова о.в.',
            'created_at'  => now()->subYear(),
        ]);
        Order::factory()->internal()->create([
            'cost_center' => 'КЛБ',
            'initiator'   => 'Іванова О.В.',
            'created_at'  => now(),
        ]);
        CostCenterInitiator::query()->delete();

        $this->artisan('cost-centers:backfill-initiators')->assertSuccessful();

        $this->assertSame('Іванова О.В.', CostCenterInitiator::first()->name);
    }

    public function test_it_leaves_hand_added_rows_alone(): void
    {
        $department = Department::factory()->create(['name' => 'БШК']);
        CostCenterInitiator::query()->delete();

        CostCenterInitiator::create([
            'department_id' => $department->id,
            'name'          => 'Новенька Н.Н.',
            'name_key'      => CostCenterInitiator::normalizeKey('Новенька Н.Н.'),
            'orders_count'  => 0,
        ]);

        $this->artisan('cost-centers:backfill-initiators')->assertSuccessful();

        $this->assertSame(1, CostCenterInitiator::count());
        $this->assertSame(0, CostCenterInitiator::first()->orders_count);
    }

    /**
     * A hand-added row is only left alone while history is unaware of its
     * pair. Once history's own `(department_id, name_key)` lands on the same
     * key, it is no longer a row history doesn't know — the backfill
     * recomputes it like any other pair, both count and spelling, from the
     * newest order. Same key semantics `CostCenterInitiator::remember()`
     * already uses; spec §4.3 was corrected to say so.
     */
    public function test_a_hand_added_row_is_recomputed_once_history_learns_its_pair(): void
    {
        $department = Department::factory()->create(['name' => 'ФПК']);

        CostCenterInitiator::create([
            'department_id' => $department->id,
            'name'          => 'Новенька Н.Н.',
            'name_key'      => CostCenterInitiator::normalizeKey('Новенька Н.Н.'),
            'orders_count'  => 0,
        ]);

        // Same person, a spelling that differs only in case/spacing — same
        // name_key as the hand-added row above.
        Order::factory()->internal()->count(2)->create([
            'cost_center' => 'ФПК',
            'initiator'   => ' новенька н.н. ',
        ]);

        $this->artisan('cost-centers:backfill-initiators')->assertSuccessful();

        $this->assertSame(1, CostCenterInitiator::count());
        $row = CostCenterInitiator::first();
        $this->assertSame(2, $row->orders_count);
        $this->assertSame('новенька н.н.', $row->name);
    }

    public function test_commercial_orders_are_not_history_for_this(): void
    {
        Department::factory()->create(['name' => 'КМПЄ']);

        Order::factory()->commercial()->create([
            'cost_center' => 'КМПЄ',
            'initiator'   => 'Іванова О.В.',
        ]);
        CostCenterInitiator::query()->delete();

        $this->artisan('cost-centers:backfill-initiators')->assertSuccessful();

        $this->assertSame(0, CostCenterInitiator::count());
    }

    /**
     * Пара, чийого центру немає в довіднику (деактивований після того, як
     * історія назбиралась), пропускається — і команда каже про це вголос,
     * а не мовчки ковтає.
     */
    public function test_a_pair_whose_centre_left_the_dictionary_is_skipped_aloud(): void
    {
        $gone = Department::factory()->create(['name' => 'Розформований відділ']);
        Department::factory()->create(['name' => 'КЖУР']);

        Order::factory()->internal()->create([
            'cost_center' => 'Розформований відділ',
            'initiator'   => 'Іванова О.В.',
        ]);
        Order::factory()->internal()->create([
            'cost_center' => 'КЖУР',
            'initiator'   => 'Петренко І.І.',
        ]);

        $gone->delete();
        CostCenterInitiator::query()->delete();

        $this->artisan('cost-centers:backfill-initiators')
            ->expectsOutput('Записано ініціаторів: 1.')
            ->expectsOutput('Пропущено (центру витрат немає в довіднику): 1.')
            ->assertSuccessful();

        $this->assertSame(['Петренко І.І.'], CostCenterInitiator::pluck('name')->all());
    }
}
