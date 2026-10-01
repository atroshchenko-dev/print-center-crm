<?php

declare(strict_types=1);

use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use Illuminate\Database\Migrations\Migration;

/**
 * Fix cover depends_on: each cover should only be visible when
 * its matching color channel is selected.
 *
 * Before: Обкладинка "Червоний" depends_on ALL channel color options
 * After:  Обкладинка "Червоний" depends_on only "Канал *: Червоний" options
 */
return new class extends Migration
{
    public function up(): void
    {
        $coverGroup = ServiceParameterGroup::whereHas('service', fn ($q) =>
            $q->whereHas('category', fn ($q2) => $q2->where('name', 'Палітурка тверда'))
        )->where('name', 'Обкладинка')->first();

        if (! $coverGroup) {
            return;
        }

        $colorGroup = ServiceParameterGroup::whereHas('service', fn ($q) =>
            $q->whereHas('category', fn ($q2) => $q2->where('name', 'Палітурка тверда'))
        )->where('name', 'Колір')->first();

        if (! $colorGroup) {
            return;
        }

        $colorOptions = ServiceParameterOption::where('group_id', $colorGroup->id)
            ->where('is_active', true)
            ->get();

        $coverOptions = ServiceParameterOption::where('group_id', $coverGroup->id)
            ->where('is_active', true)
            ->get();

        foreach ($coverOptions as $cover) {
            $coverColor = trim($cover->name); // e.g. "Червоний"

            // Find ALL channel color options that end with this color name
            // e.g. "Канал 5мм: Червоний", "Канал 7мм: Червоний", ...
            $matchingIds = $colorOptions
                ->filter(fn ($opt) => str_ends_with($opt->name, ": {$coverColor}"))
                ->pluck('id')
                ->values()
                ->toArray();

            if (empty($matchingIds)) {
                continue;
            }

            $cover->update([
                'depends_on' => [
                    'group_id'   => $colorGroup->id,
                    'option_ids' => $matchingIds,
                ],
            ]);
        }
    }

    public function down(): void
    {
        // Reversal: restore depends_on to ALL channel color options
        $coverGroup = ServiceParameterGroup::whereHas('service', fn ($q) =>
            $q->whereHas('category', fn ($q2) => $q2->where('name', 'Палітурка тверда'))
        )->where('name', 'Обкладинка')->first();

        if (! $coverGroup) {
            return;
        }

        $colorGroup = ServiceParameterGroup::whereHas('service', fn ($q) =>
            $q->whereHas('category', fn ($q2) => $q2->where('name', 'Палітурка тверда'))
        )->where('name', 'Колір')->first();

        if (! $colorGroup) {
            return;
        }

        $allChannelColorIds = ServiceParameterOption::where('group_id', $colorGroup->id)
            ->where('is_active', true)
            ->where('name', 'like', 'Канал%')
            ->pluck('id')
            ->toArray();

        ServiceParameterOption::where('group_id', $coverGroup->id)
            ->where('is_active', true)
            ->update([
                'depends_on' => [
                    'group_id'   => $colorGroup->id,
                    'option_ids' => $allChannelColorIds,
                ],
            ]);
    }
};
