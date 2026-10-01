<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Department;
use App\Models\Order;
use App\Models\SignatoryCostCenter;

/**
 * OrderObserver — усе, що замовлення дописує в довідники.
 *
 * Дві дії, і обидві раніше жили в різних місцях:
 *
 * 1. `Department::remember()` — центр витрат, названий замовленням, лишається
 *    в довіднику (ТЗ §3.1). Це кликали з п'яти місць: обидва шляхи замовлення,
 *    обидва ретро, ретро-імпорт.
 * 2. `SignatoryCostCenter::remember()` — пара «підписант ↔ центр витрат».
 *
 * Порядок між ними не був випадковим збігом, а вимогою: пара шукає підрозділ
 * у довіднику, тож підрозділ мусить існувати **раніше**. Усі п'ять місць
 * кликали `Department::remember()` після `Order::create()` — тобто після того,
 * як спостерігач уже відпрацював, — і центр витрат, створений цим самим
 * замовленням, пари не утворював. Оператор натискав «+ Новий центр витрат»,
 * зберігав, і пара з'являлася аж на другому такому замовленні.
 *
 * Тому обидві дії тепер тут, одна за одною. Це рівно те, чого просить докблок
 * `Department::remember()` («шостий викликач не може забути»): шостий шлях
 * запису замовлення отримує і довідник, і пару, і в правильному порядку,
 * не написавши жодного рядка.
 *
 * Слухається `created` і `updated`, не `saved`: замовлення зберігається багато
 * разів за життя, і на `saved` лічильник рахував би збереження замість
 * замовлень.
 */
class OrderObserver
{
    public function created(Order $order): void
    {
        $this->rememberCostCentre($order);
        $this->rememberPair($order);
    }

    /**
     * Перепризначення підписанта чи центру витрат — новий факт, який
     * заслуговує пари. Решта оновлень (статус, оплата, звірка) — ні.
     *
     * Стара пара лишається зі своїм лічильником: те замовлення справді було
     * оформлене на неї. Прибрати помилкову пару — робота адмінки.
     */
    public function updated(Order $order): void
    {
        if (! $order->wasChanged(['authorized_person', 'cost_center'])) {
            return;
        }

        $this->rememberCostCentre($order);
        $this->rememberPair($order);
    }

    /**
     * Довідник підрозділів — для замовлення будь-якого типу: комерційне теж
     * може нести центр витрат, і всі п'ять попередніх викликачів писали його
     * без огляду на тип.
     */
    private function rememberCostCentre(Order $order): void
    {
        Department::remember($order->cost_center);
    }

    private function rememberPair(Order $order): void
    {
        if (! $order->isInternal()) {
            return;
        }

        SignatoryCostCenter::remember(
            $order->authorized_person,
            $order->cost_center,
            $order->created_at,
        );
    }
}
