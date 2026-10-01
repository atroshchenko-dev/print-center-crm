<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderType;
use App\Models\CostCenterInitiator;
use App\Models\Department;
use App\Models\Order;
use Illuminate\Console\Command;

/**
 * Разовий прохід по історії: хто замовляв від якого підрозділу.
 *
 * Виставляє абсолютні значення, а не інкрементує, тому запуск удруге дає той
 * самий результат.
 *
 * Рядок, доданий в адмінці руками (`orders_count = 0`), лишається недоторканим
 * доти, доки історія не знає його пари. Щойно знає — рядок перераховується як
 * будь-який інший: і `name`, і `orders_count` беруться з історії, бо пара, яка
 * в ній є, за визначенням більше не є парою, про яку історія мовчить. Це
 * закріплено `test_a_hand_added_row_is_recomputed_once_history_learns_its_pair`.
 */
class BackfillCostCenterInitiatorsCommand extends Command
{
    protected $signature = 'cost-centers:backfill-initiators';

    protected $description = 'Наповнити довідник «центр витрат ↔ ініціатор» з наявних замовлень';

    public function handle(): int
    {
        $departments = Department::pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim((string) $name)) => $id]);

        // `toBase()` — рядки читаються як прості записи, а не гідруються
        // в Order: моделі тут не потрібні, а PHPStan level 5 справедливо
        // лається на властивості, яких у моделі немає.
        $rows = Order::query()
            ->where('type', OrderType::Internal->value)
            ->whereNotNull('cost_center')
            ->whereNotNull('initiator')
            ->orderBy('created_at')
            ->orderBy('id')
            ->toBase()
            ->get(['cost_center', 'initiator', 'created_at']);

        $pairs = [];

        foreach ($rows as $row) {
            $centreKey = mb_strtolower(trim((string) $row->cost_center));
            $nameKey = CostCenterInitiator::normalizeKey($row->initiator);

            if ($centreKey === '' || $nameKey === '') {
                continue;
            }

            $bucket = $centreKey.'|'.$nameKey;

            // Рядки йдуть за зростанням дати, тож останній побачений — найновіший:
            // його написання і його дата й перемагають.
            $pairs[$bucket] = [
                'centre_key'   => $centreKey,
                'name_key'     => $nameKey,
                'name'         => trim((string) $row->initiator),
                'orders_count' => ($pairs[$bucket]['orders_count'] ?? 0) + 1,
                'last_used_at' => $row->created_at,
            ];
        }

        $written = 0;
        $skipped = 0;

        foreach ($pairs as $pair) {
            $departmentId = $departments[$pair['centre_key']] ?? null;

            if (! $departmentId) {
                $skipped++;

                continue;
            }

            CostCenterInitiator::updateOrCreate(
                [
                    'department_id' => $departmentId,
                    'name_key'      => $pair['name_key'],
                ],
                [
                    'name'         => $pair['name'],
                    'orders_count' => $pair['orders_count'],
                    'last_used_at' => $pair['last_used_at'],
                ],
            );

            $written++;
        }

        $this->info("Записано ініціаторів: {$written}.");

        if ($skipped > 0) {
            $this->warn("Пропущено (центру витрат немає в довіднику): {$skipped}.");
        }

        return self::SUCCESS;
    }
}
