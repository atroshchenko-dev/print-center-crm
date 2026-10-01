<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CostCenterInitiator;
use App\Models\Order;

/**
 * OrderInitiatorObserver — хто замовляв від цього підрозділу.
 *
 * Окремий клас, а не ще один метод в `OrderObserver`: той відповідає за
 * довідник підрозділів і пари підписанта, цей — за імена людей. Дві логіки
 * в одному файлі розходяться першою ж правкою, зробленою поспіхом.
 *
 * **Порядок реєстрації значущий.** `OrderObserver` заводить у довідник центр
 * витрат, названий цим самим замовленням, і мусить відпрацювати раніше —
 * інакше `CostCenterInitiator::remember()` не знайде підрозділу й мовчки нічого
 * не запише. Саме цей дефект минулий раунд ловив уже після реалізації:
 * оператор називав новий підрозділ, зберігав, і пара з'являлась аж на другому
 * такому замовленні.
 *
 * Слухається `created` і `updated`, не `saved`: замовлення зберігається багато
 * разів за життя, і на `saved` лічильник рахував би збереження.
 */
class OrderInitiatorObserver
{
    public function created(Order $order): void
    {
        $this->rememberInitiator($order);
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged(['cost_center', 'initiator'])) {
            return;
        }

        $this->rememberInitiator($order);
    }

    private function rememberInitiator(Order $order): void
    {
        if (! $order->isInternal()) {
            return;
        }

        CostCenterInitiator::remember(
            $order->cost_center,
            $order->initiator,
            $order->created_at,
        );
    }
}
