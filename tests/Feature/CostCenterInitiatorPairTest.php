<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\CostCenterInitiator;
use App\Models\Department;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Хто замовляє від цього підрозділу.
 *
 * `orders.initiator` — вільний текст без жодного довідника за ним, тож одна
 * людина цілком може бути записана трьома способами. Зводиться регістр
 * і пробіли; показується те написання, яким її назвали вперше.
 */
class CostCenterInitiatorPairTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_the_pair_from_plain_names(): void
    {
        $department = Department::factory()->create(['name' => 'Кафедра туризму']);

        CostCenterInitiator::remember('Кафедра туризму', 'Іванова О.В.');

        $this->assertDatabaseHas('cost_center_initiators', [
            'department_id' => $department->id,
            'name'          => 'Іванова О.В.',
            'name_key'      => 'іванова о.в.',
            'orders_count'  => 1,
        ]);
    }

    public function test_case_and_spacing_fold_into_one_row(): void
    {
        Department::factory()->create(['name' => 'КЖУР']);

        CostCenterInitiator::remember('КЖУР', 'Іванова О.В.');
        CostCenterInitiator::remember('кжур', '  іванова о.в. ');

        $this->assertSame(1, CostCenterInitiator::count());
        $this->assertSame(2, CostCenterInitiator::first()->orders_count);
    }

    /**
     * Друге написання не переписує перше: система показує те ім'я, яким
     * людину назвали спочатку, а не останній варіант чийогось набору.
     */
    public function test_the_first_spelling_is_the_one_kept(): void
    {
        Department::factory()->create(['name' => 'ДКДЗ']);

        CostCenterInitiator::remember('ДКДЗ', 'Іванова О.В.');
        CostCenterInitiator::remember('ДКДЗ', 'іванова о.в.');

        $this->assertSame('Іванова О.В.', CostCenterInitiator::first()->name);
    }

    public function test_a_different_person_is_a_different_row(): void
    {
        Department::factory()->create(['name' => 'КЛБ']);

        CostCenterInitiator::remember('КЛБ', 'Іванова О.В.');
        CostCenterInitiator::remember('КЛБ', 'Іванова Олена');

        $this->assertSame(2, CostCenterInitiator::count());
    }

    public function test_it_keeps_the_latest_use_and_never_moves_it_backwards(): void
    {
        Department::factory()->create(['name' => 'КМВЖ']);

        CostCenterInitiator::remember('КМВЖ', 'Петренко І.І.', now()->subMonth());
        CostCenterInitiator::remember('КМВЖ', 'Петренко І.І.', now());
        CostCenterInitiator::remember('КМВЖ', 'Петренко І.І.', now()->subYear());

        $this->assertTrue(CostCenterInitiator::first()->last_used_at->isToday());
    }

    public function test_an_unknown_centre_or_empty_name_writes_nothing(): void
    {
        Department::factory()->create(['name' => 'БШК']);

        // Контроль: механізм узагалі пише — інакше нулі нижче доводили б
        // лише те, що remember() не робить нічого ніколи.
        CostCenterInitiator::remember('БШК', 'Контрольна К.К.');
        $this->assertSame(1, CostCenterInitiator::count());

        CostCenterInitiator::remember('Підрозділ, якого немає', 'Іванова О.В.');
        CostCenterInitiator::remember('БШК', '');
        CostCenterInitiator::remember('БШК', null);
        CostCenterInitiator::remember(null, 'Іванова О.В.');

        $this->assertSame(1, CostCenterInitiator::count());
    }

    public function test_a_deactivated_centre_does_not_get_new_pairs(): void
    {
        $department = Department::factory()->create(['name' => 'КТУР']);

        // Контроль: живий центр пару приймає…
        CostCenterInitiator::remember('КТУР', 'Контрольна К.К.');
        $this->assertSame(1, CostCenterInitiator::count());

        $department->delete();

        // …деактивований — уже ні.
        CostCenterInitiator::remember('КТУР', 'Іванова О.В.');

        $this->assertSame(1, CostCenterInitiator::count());
    }

    public function test_a_new_internal_order_records_the_initiator(): void
    {
        Http::fake();
        Department::factory()->create(['name' => 'Кафедра психології']);

        Order::factory()->internal()->create([
            'cost_center' => 'Кафедра психології',
            'initiator'   => 'Іванова О.В.',
        ]);

        $this->assertSame(1, CostCenterInitiator::count());
        $this->assertSame(1, CostCenterInitiator::first()->orders_count);
    }

    public function test_a_commercial_order_records_nothing(): void
    {
        Http::fake();
        Department::factory()->create(['name' => 'КЛБ']);

        Order::factory()->commercial()->create([
            'cost_center' => 'КЛБ',
            'initiator'   => 'Іванова О.В.',
        ]);

        $this->assertSame(0, CostCenterInitiator::count());

        // Контроль: те саме замовлення, але внутрішнє, пару дає — нуль вище
        // означає «комерційне відсіяно», а не «спостерігач не підключений».
        Order::factory()->internal()->create([
            'cost_center' => 'КЛБ',
            'initiator'   => 'Іванова О.В.',
        ]);

        $this->assertSame(1, CostCenterInitiator::count());
    }

    public function test_an_order_without_an_initiator_records_nothing(): void
    {
        Http::fake();
        Department::factory()->create(['name' => 'КЛБ']);

        Order::factory()->internal()->create([
            'cost_center' => 'КЛБ',
            'initiator'   => null,
        ]);

        $this->assertSame(0, CostCenterInitiator::count());

        // Контроль: додати ініціатора — і той самий шлях пише пару.
        Order::factory()->internal()->create([
            'cost_center' => 'КЛБ',
            'initiator'   => 'Іванова О.В.',
        ]);

        $this->assertSame(1, CostCenterInitiator::count());
    }

    /**
     * Замовлення зберігається багато разів за життя — статус, оплата, звірка.
     * На `saved` лічильник рахував би збереження замість замовлень.
     */
    public function test_saving_the_order_again_does_not_inflate_the_counter(): void
    {
        Http::fake();
        Department::factory()->create(['name' => 'БЩК']);

        $order = Order::factory()->internal()->create([
            'cost_center' => 'БЩК',
            'initiator'   => 'Петренко І.І.',
        ]);

        $order->update(['status' => OrderStatus::InProgress->value]);
        $order->update(['total_cost' => 42.00]);

        $this->assertSame(1, CostCenterInitiator::first()->orders_count);
    }

    /**
     * Центр витрат, названий уперше цим самим замовленням, мусить дати пару.
     * Це тримається на порядку спостерігачів: `OrderObserver` заводить
     * підрозділ у довідник і йде **першим**.
     */
    public function test_a_centre_invented_by_the_order_still_pairs(): void
    {
        Http::fake();

        Order::factory()->internal()->create([
            'cost_center' => 'Відділ аспірантури',
            'initiator'   => 'Іванова О.В.',
        ]);

        $this->assertDatabaseHas('departments', ['name' => 'Відділ аспірантури']);
        $this->assertSame(1, CostCenterInitiator::count());
    }
}
