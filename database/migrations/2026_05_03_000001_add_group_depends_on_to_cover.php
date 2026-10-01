<?php

declare(strict_types=1);

use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use Illuminate\Database\Migrations\Migration;

/**
 * Add group-level depends_on to the "Обкладинка" group in hard binding.
 *
 * Previously: cover group had NO group-level depends_on — relied solely
 * on option-level filtering, which allowed it to appear for "Комплектна"
 * type when option data was inconsistent.
 *
 * After: cover group depends_on Тип палітурки → "Канал + Обкладинка" only.
 * This guarantees the entire section is hidden for "Комплектна" type.
 */
return new class extends Migration
{
    public function up(): void
    {
        $typeGroup = ServiceParameterGroup::whereHas('service', fn ($q) =>
            $q->whereHas('category', fn ($q2) => $q2->where('name', 'Палітурка тверда'))
        )->where('name', 'Тип палітурки')->first();

        if (! $typeGroup) {
            return;
        }

        $channelOption = ServiceParameterOption::where('group_id', $typeGroup->id)
            ->where('name', 'Канал + Обкладинка')
            ->first();

        if (! $channelOption) {
            return;
        }

        $coverGroup = ServiceParameterGroup::whereHas('service', fn ($q) =>
            $q->whereHas('category', fn ($q2) => $q2->where('name', 'Палітурка тверда'))
        )->where('name', 'Обкладинка')->first();

        if (! $coverGroup) {
            return;
        }

        $coverGroup->update([
            'depends_on' => [
                'group_id'   => $typeGroup->id,
                'option_ids' => [$channelOption->id],
            ],
        ]);
    }

    public function down(): void
    {
        $coverGroup = ServiceParameterGroup::whereHas('service', fn ($q) =>
            $q->whereHas('category', fn ($q2) => $q2->where('name', 'Палітурка тверда'))
        )->where('name', 'Обкладинка')->first();

        if ($coverGroup) {
            $coverGroup->update(['depends_on' => null]);
        }
    }
};
