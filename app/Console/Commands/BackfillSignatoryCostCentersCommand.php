<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderType;
use App\Models\Department;
use App\Models\Order;
use App\Models\SignatoryCostCenter;
use App\Models\UniversityRef;
use Illuminate\Console\Command;

/**
 * Разовий прохід по історії замовлень: які центри витрат за ким водяться.
 *
 * Виставляє абсолютні значення, а не інкрементує, тому запуск удруге дає
 * той самий результат. Рядки, додані в адмінці руками (`orders_count = 0`),
 * не чіпаються: історія про них нічого не знає, і це не привід їх стирати.
 */
class BackfillSignatoryCostCentersCommand extends Command
{
    protected $signature = 'signatories:backfill-cost-centers';

    protected $description = 'Наповнити довідник «підписант ↔ центр витрат» з наявних замовлень';

    public function handle(): int
    {
        $signatories = UniversityRef::pluck('id', 'full_name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim((string) $name)) => $id]);

        $departments = Department::pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim((string) $name)) => $id]);

        // `->toBase()` before `->get()`: this aggregate has no `id`, no
        // `authorized_person`/`cost_center` of its own — it is not an Order,
        // it is a row of counts about orders. Hydrating it as one would let
        // `$pair->person` etc. through as undeclared dynamic properties;
        // `toBase()` hands back plain `stdClass` rows, which is what these are.
        $pairs = Order::query()
            ->where('type', OrderType::Internal->value)
            ->whereNotNull('authorized_person')
            ->whereNotNull('cost_center')
            ->selectRaw('LOWER(TRIM(authorized_person)) as person')
            ->selectRaw('LOWER(TRIM(cost_center)) as centre')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('MAX(created_at) as last_used_at')
            ->groupByRaw('LOWER(TRIM(authorized_person)), LOWER(TRIM(cost_center))')
            ->toBase()
            ->get();

        $written = 0;
        $skipped = 0;

        foreach ($pairs as $pair) {
            $signatoryId = $signatories[$pair->person] ?? null;
            $departmentId = $departments[$pair->centre] ?? null;

            if (! $signatoryId || ! $departmentId) {
                $skipped++;

                continue;
            }

            SignatoryCostCenter::updateOrCreate(
                [
                    'university_ref_id' => $signatoryId,
                    'department_id'     => $departmentId,
                ],
                [
                    'orders_count' => (int) $pair->orders_count,
                    'last_used_at' => $pair->last_used_at,
                ],
            );

            $written++;
        }

        $this->info("Записано пар: {$written}.");

        if ($skipped > 0) {
            $this->warn("Пропущено (підписанта або центру немає в довіднику): {$skipped}.");
        }

        return self::SUCCESS;
    }
}
