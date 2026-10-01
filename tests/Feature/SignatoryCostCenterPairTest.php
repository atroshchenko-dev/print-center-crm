<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Department;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SignatoryCostCenter;
use App\Models\UniversityRef;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Пара «підписант ↔ центр витрат» виводиться з тексту, який лежить у замовленні.
 * Тому зіставлення тут — те саме нормалізоване, що й у Department::remember():
 * інакше «Кафедра ІМЗД» і «кафедра ІМЗД» дали б дві різні пари, а в проді
 * така пара існує.
 */
class SignatoryCostCenterPairTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_pair_from_plain_names(): void
    {
        $signatory = UniversityRef::factory()->create(['full_name' => 'Накченко Н.В.']);
        $department = Department::factory()->create(['name' => 'Департамент реклами']);

        SignatoryCostCenter::remember('Накченко Н.В.', 'Департамент реклами');

        $this->assertDatabaseHas('signatory_cost_centers', [
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
            'orders_count'      => 1,
        ]);
    }

    public function test_it_matches_ignoring_case_and_spaces(): void
    {
        $signatory = UniversityRef::factory()->create(['full_name' => 'Трочук В.В.']);
        $department = Department::factory()->create(['name' => 'Кафедра ІМЗД']);

        SignatoryCostCenter::remember('  трочук в.в. ', 'кафедра ІМЗД');

        $this->assertDatabaseHas('signatory_cost_centers', [
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
            'orders_count'      => 1,
        ]);
        $this->assertSame(1, SignatoryCostCenter::count());
    }

    public function test_a_second_order_raises_the_counter_without_a_second_row(): void
    {
        UniversityRef::factory()->create(['full_name' => 'Новаський І.М.']);
        Department::factory()->create(['name' => 'КЖУР']);

        SignatoryCostCenter::remember('Новаський І.М.', 'КЖУР');
        SignatoryCostCenter::remember('Новаський І.М.', 'КЖУР');

        $this->assertSame(1, SignatoryCostCenter::count());
        $this->assertSame(2, SignatoryCostCenter::first()->orders_count);
    }

    public function test_it_keeps_the_latest_use_and_never_moves_it_backwards(): void
    {
        UniversityRef::factory()->create(['full_name' => 'Ткський Д.І.']);
        Department::factory()->create(['name' => 'КЛБ']);

        SignatoryCostCenter::remember('Ткський Д.І.', 'КЛБ', now()->subMonth());
        SignatoryCostCenter::remember('Ткський Д.І.', 'КЛБ', now());
        SignatoryCostCenter::remember('Ткський Д.І.', 'КЛБ', now()->subYear());

        $this->assertTrue(SignatoryCostCenter::first()->last_used_at->isToday());
    }

    public function test_an_unknown_signatory_or_centre_writes_nothing(): void
    {
        UniversityRef::factory()->create(['full_name' => 'Балдець Д.О.']);
        Department::factory()->create(['name' => 'КМВЖ']);

        SignatoryCostCenter::remember('Хтось Невідомий', 'КМВЖ');
        SignatoryCostCenter::remember('Балдець Д.О.', 'Підрозділ, якого немає');
        SignatoryCostCenter::remember('', 'КМВЖ');
        SignatoryCostCenter::remember('Балдець Д.О.', null);

        $this->assertSame(0, SignatoryCostCenter::count());
    }

    public function test_a_deactivated_signatory_does_not_get_new_pairs(): void
    {
        $signatory = UniversityRef::factory()->create(['full_name' => 'Фініник Т.В.']);
        Department::factory()->create(['name' => 'КТУР']);
        $signatory->delete();

        SignatoryCostCenter::remember('Фініник Т.В.', 'КТУР');

        $this->assertSame(0, SignatoryCostCenter::count());
    }

    public function test_a_new_internal_order_records_the_pair(): void
    {
        Http::fake();
        UniversityRef::factory()->create(['full_name' => 'Прохрина М.Е.']);
        Department::factory()->create(['name' => 'КМПЄ']);

        Order::factory()->internal()->create([
            'authorized_person' => 'Прохрина М.Е.',
            'cost_center'       => 'КМПЄ',
        ]);

        $this->assertSame(1, SignatoryCostCenter::count());
        $this->assertSame(1, SignatoryCostCenter::first()->orders_count);
    }

    public function test_a_commercial_order_records_nothing(): void
    {
        Http::fake();
        UniversityRef::factory()->create(['full_name' => 'Іщчук Л.В.']);
        Department::factory()->create(['name' => 'ДДП']);

        Order::factory()->commercial()->create([
            'authorized_person' => 'Іщчук Л.В.',
            'cost_center'       => 'ДДП',
        ]);

        $this->assertSame(0, SignatoryCostCenter::count());
    }

    /**
     * Замовлення зберігається багато разів за життя — статус, оплата, звірка.
     * На `saved` лічильник рахував би збереження замість замовлень і роздував
     * би частоту тим парам, чиї замовлення довше ходили по статусах.
     */
    public function test_saving_the_order_again_does_not_inflate_the_counter(): void
    {
        Http::fake();
        UniversityRef::factory()->create(['full_name' => 'Горський О.М.']);
        Department::factory()->create(['name' => 'БШК']);

        $order = Order::factory()->internal()->create([
            'authorized_person' => 'Горський О.М.',
            'cost_center'       => 'БШК',
        ]);

        $order->update(['status' => OrderStatus::InProgress->value]);
        $order->update(['total_cost' => 42.00]);

        $this->assertSame(1, SignatoryCostCenter::first()->orders_count);
    }

    public function test_changing_the_cost_centre_records_the_new_pair(): void
    {
        Http::fake();
        UniversityRef::factory()->create(['full_name' => 'Прилченко С.М.']);
        Department::factory()->create(['name' => 'КУТ, ННІМО']);
        Department::factory()->create(['name' => 'КУТ, ННІІКТ']);

        $order = Order::factory()->internal()->create([
            'authorized_person' => 'Прилченко С.М.',
            'cost_center'       => 'КУТ, ННІМО',
        ]);

        $order->update(['cost_center' => 'КУТ, ННІІКТ']);

        $this->assertSame(2, SignatoryCostCenter::count());
    }

    /**
     * `$order->update()` above goes through `Model::save()`, which fires
     * Eloquent events on its own — it would pass even if the observer were
     * never wired up correctly for the real edit path. The ordinary way an
     * operator corrects a live order is `OrderController::update()`, which
     * writes through `Order::saveWithOptimisticLock()` — a query-builder
     * mass update that does not fire model events unless the method makes
     * it do so. This test exercises that HTTP route so a regression there
     * is caught here rather than in production.
     */
    public function test_changing_the_cost_centre_through_the_controller_records_the_new_pair(): void
    {
        Http::fake();
        UniversityRef::factory()->create(['full_name' => 'Дорошенко П.П.']);
        Department::factory()->create(['name' => 'КІТ']);
        Department::factory()->create(['name' => 'КМФ']);

        $executor = User::factory()->create(['role' => 'executor']);
        $equipment = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);
        app(ShiftService::class)->openShift($executor, [
            ['equipment_id' => $equipment->id, 'counter_value' => 10_000],
        ]);

        $category = ServiceCategory::factory()->create([
            'available_for' => ['internal', 'commercial'],
        ]);
        $service = Service::factory()->create([
            'service_category_id'   => $category->id,
            'type'                  => 'static',
            'base_price_cost'       => 2.50,
            'base_price_commercial' => 5.00,
            'is_active'             => true,
        ]);

        $order = Order::factory()->internal()->create([
            'authorized_person' => 'Дорошенко П.П.',
            'cost_center'       => 'КІТ',
        ]);
        $order->refresh(); // load the DB default for `version` (see OrderOptimisticLockTest::setUp())
        $this->assertSame(1, SignatoryCostCenter::count());

        $response = $this->actingAs($executor)->put(route('orders.update', $order), [
            'type'              => 'internal',
            'authorized_person' => 'Дорошенко П.П.',
            'cost_center'       => 'КМФ',
            'version'           => $order->version,
            'items'             => [
                ['service_id' => $service->id, 'quantity' => 1],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertSame('КМФ', $order->fresh()->cost_center);
        $this->assertSame(2, SignatoryCostCenter::count());
    }

    /**
     * The branch's own new primary path: the operator picks a signatory, clicks
     * «+ Новий центр витрат», types a subdivision that is not in the book yet,
     * and saves. Every existing test above creates the department by factory
     * first, so none of them could see that all five order writers used to call
     * `Department::remember()` *after* the order was saved — by which time the
     * observer had already looked for that department, not found it and written
     * no pair. The pair appeared only on the second order naming it, and its
     * count stayed one short for good.
     */
    public function test_a_cost_centre_invented_by_the_order_pairs_on_that_very_order(): void
    {
        Http::fake();
        $signatory = UniversityRef::factory()->create(['full_name' => 'Кулик С.М.']);

        $order = Order::factory()->internal()->create([
            'authorized_person' => 'Кулик С.М.',
            'cost_center'       => 'Відділ аспірантури',
        ]);

        $department = Department::where('name', 'Відділ аспірантури')->first();
        $this->assertNotNull($department, 'The order has to put its new cost centre in the book.');

        $this->assertDatabaseHas('signatory_cost_centers', [
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
            'orders_count'      => 1,
        ]);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    /**
     * `firstOrNew` + `save` against a unique index is read-then-write: two
     * operators saving the first-ever order for the same pair at the same
     * moment both read nothing and both insert. The loser used to get the
     * constraint violation thrown out of the order's own transaction, which
     * rolled the whole order back — the one thing this method's docblock
     * promises never to do.
     *
     * The race is reproduced rather than described: a `creating` hook inserts
     * the conflicting row on the same connection in the instant between the
     * read and the write, which is exactly where the real one happens.
     */
    public function test_a_concurrent_identical_pair_does_not_take_the_order_down(): void
    {
        $signatory = UniversityRef::factory()->create(['full_name' => 'Ковальчук О.Р.']);
        $department = Department::factory()->create(['name' => 'ННІПП']);

        SignatoryCostCenter::creating(function () use ($signatory, $department) {
            DB::table('signatory_cost_centers')->insert([
                'university_ref_id' => $signatory->id,
                'department_id'     => $department->id,
                'orders_count'      => 1,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        });

        SignatoryCostCenter::remember('Ковальчук О.Р.', 'ННІПП');

        $this->assertTrue(true, 'remember() must not throw when it loses the race.');
    }

    public function test_an_order_with_an_unknown_signatory_still_saves(): void
    {
        Http::fake();
        Department::factory()->create(['name' => 'ВАіД']);

        $order = Order::factory()->internal()->create([
            'authorized_person' => 'Особа, якої немає в довіднику',
            'cost_center'       => 'ВАіД',
        ]);

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertSame(0, SignatoryCostCenter::count());
    }
}
