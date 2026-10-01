<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Order;
use App\Models\SignatoryCostCenter;
use App\Models\UniversityRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Пари накопичуються з нових замовлень, але довідник має бути корисним
 * з першого дня, а не через півроку. Звідси разовий прохід по історії.
 *
 * Головна вимога — ідемпотентність: команду запустять двічі, і другий раз
 * не має подвоїти лічильники.
 */
class SignatoryCostCenterBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    public function test_it_counts_the_pairs_that_history_holds(): void
    {
        UniversityRef::factory()->create(['full_name' => 'Накченко Н.В.']);
        Department::factory()->create(['name' => 'Департамент реклами']);

        Order::factory()->internal()->count(3)->create([
            'authorized_person' => 'Накченко Н.В.',
            'cost_center'       => 'Департамент реклами',
        ]);
        SignatoryCostCenter::query()->delete();

        $this->artisan('signatories:backfill-cost-centers')->assertSuccessful();

        $this->assertSame(1, SignatoryCostCenter::count());
        $this->assertSame(3, SignatoryCostCenter::first()->orders_count);
    }

    public function test_running_it_twice_does_not_double_the_counters(): void
    {
        UniversityRef::factory()->create(['full_name' => 'Старчович А.М.']);
        Department::factory()->create(['name' => 'БЩК']);

        Order::factory()->internal()->count(2)->create([
            'authorized_person' => 'Старчович А.М.',
            'cost_center'       => 'БЩК',
        ]);
        SignatoryCostCenter::query()->delete();

        $this->artisan('signatories:backfill-cost-centers')->assertSuccessful();
        $this->artisan('signatories:backfill-cost-centers')->assertSuccessful();

        $this->assertSame(2, SignatoryCostCenter::first()->orders_count);
    }

    public function test_it_folds_case_and_spacing_into_one_pair(): void
    {
        UniversityRef::factory()->create(['full_name' => 'Трочук В.В.']);
        Department::factory()->create(['name' => 'Кафедра ІМЗД']);

        Order::factory()->internal()->create([
            'authorized_person' => 'Трочук В.В.',
            'cost_center'       => 'Кафедра ІМЗД',
        ]);
        Order::factory()->internal()->create([
            'authorized_person' => ' трочук в.в.',
            'cost_center'       => 'кафедра ІМЗД ',
        ]);
        SignatoryCostCenter::query()->delete();

        $this->artisan('signatories:backfill-cost-centers')->assertSuccessful();

        $this->assertSame(1, SignatoryCostCenter::count());
        $this->assertSame(2, SignatoryCostCenter::first()->orders_count);
    }

    public function test_it_leaves_hand_added_pairs_alone(): void
    {
        $signatory = UniversityRef::factory()->create(['full_name' => 'Новаський О.М.']);
        $department = Department::factory()->create(['name' => 'ДКДЗ']);
        SignatoryCostCenter::query()->delete();

        SignatoryCostCenter::create([
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
            'orders_count'      => 0,
        ]);

        $this->artisan('signatories:backfill-cost-centers')->assertSuccessful();

        $this->assertSame(1, SignatoryCostCenter::count());
        $this->assertSame(0, SignatoryCostCenter::first()->orders_count);
    }

    public function test_commercial_orders_are_not_history_for_this(): void
    {
        UniversityRef::factory()->create(['full_name' => 'Титвак Н.В.']);
        Department::factory()->create(['name' => 'КЛБ']);

        Order::factory()->commercial()->create([
            'authorized_person' => 'Титвак Н.В.',
            'cost_center'       => 'КЛБ',
        ]);
        SignatoryCostCenter::query()->delete();

        $this->artisan('signatories:backfill-cost-centers')->assertSuccessful();

        $this->assertSame(0, SignatoryCostCenter::count());
    }
}
