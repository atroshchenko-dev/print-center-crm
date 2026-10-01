<?php

declare(strict_types=1);

use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use Illuminate\Database\Migrations\Migration;

/**
 * Set commercial prices for hard binding size options.
 *
 * Price tiers by page count (грн/екз.):
 *   до 145 стор. → 230
 *   до 185 стор. → 250
 *   до 215 стор. → 270
 *   до 255 стор. → 290
 *   до 300 стор. → 300
 *
 * Also soft-deletes grey (Сірий) color options no longer offered.
 */
return new class extends Migration
{
    /** Complete size → commercial price mapping */
    private const COMPLETE_PRICES = [
        '3.5'  => 230.00,
        '7'    => 230.00,
        '10.5' => 230.00,
        '14'   => 230.00,
        '17.5' => 250.00,
        '21'   => 270.00,
        '24.5' => 290.00,
        '28'   => 300.00,
    ];

    /** Channel size → commercial price mapping */
    private const CHANNEL_PRICES = [
        '5'  => 230.00,
        '7'  => 230.00,
        '10' => 230.00,
        '13' => 230.00,
        '16' => 250.00,
        '20' => 270.00,
        '24' => 290.00,
        '28' => 300.00,
        '32' => 300.00,
    ];

    public function up(): void
    {
        $sizeGroup = ServiceParameterGroup::whereHas('service', fn ($q) =>
            $q->whereHas('category', fn ($q2) => $q2->where('name', 'Палітурка тверда'))
        )->where('name', 'Розмір')->first();

        if (! $sizeGroup) {
            return;
        }

        // Set commercial prices on Complete size options
        foreach (self::COMPLETE_PRICES as $mm => $price) {
            ServiceParameterOption::where('group_id', $sizeGroup->id)
                ->where('name', 'like', "Комплектна: {$mm} мм%")
                ->update(['price_markup' => $price]);
        }

        // Set commercial prices on Channel size options
        foreach (self::CHANNEL_PRICES as $mm => $price) {
            ServiceParameterOption::where('group_id', $sizeGroup->id)
                ->where('name', 'like', "Канал: {$mm} мм%")
                ->update(['price_markup' => $price]);
        }

        // Soft-delete grey (Сірий) color options
        $colorGroup = ServiceParameterGroup::whereHas('service', fn ($q) =>
            $q->whereHas('category', fn ($q2) => $q2->where('name', 'Палітурка тверда'))
        )->where('name', 'Колір')->first();

        if ($colorGroup) {
            ServiceParameterOption::where('group_id', $colorGroup->id)
                ->where('name', 'like', '%Сірий%')
                ->whereNull('deleted_at')
                ->each(fn ($opt) => $opt->delete());
        }

        // Soft-delete grey cover option
        $coverGroup = ServiceParameterGroup::whereHas('service', fn ($q) =>
            $q->whereHas('category', fn ($q2) => $q2->where('name', 'Палітурка тверда'))
        )->where('name', 'Обкладинка')->first();

        if ($coverGroup) {
            ServiceParameterOption::where('group_id', $coverGroup->id)
                ->where('name', 'Сірий')
                ->whereNull('deleted_at')
                ->each(fn ($opt) => $opt->delete());
        }
    }

    public function down(): void
    {
        $sizeGroup = ServiceParameterGroup::whereHas('service', fn ($q) =>
            $q->whereHas('category', fn ($q2) => $q2->where('name', 'Палітурка тверда'))
        )->where('name', 'Розмір')->first();

        if ($sizeGroup) {
            // Reset all size options to 0
            ServiceParameterOption::where('group_id', $sizeGroup->id)
                ->update(['price_markup' => 0]);
        }

        // Restore soft-deleted grey options
        $colorGroup = ServiceParameterGroup::whereHas('service', fn ($q) =>
            $q->whereHas('category', fn ($q2) => $q2->where('name', 'Палітурка тверда'))
        )->where('name', 'Колір')->first();

        if ($colorGroup) {
            ServiceParameterOption::withTrashed()
                ->where('group_id', $colorGroup->id)
                ->where('name', 'like', '%Сірий%')
                ->each(fn ($opt) => $opt->restore());
        }

        $coverGroup = ServiceParameterGroup::whereHas('service', fn ($q) =>
            $q->whereHas('category', fn ($q2) => $q2->where('name', 'Палітурка тверда'))
        )->where('name', 'Обкладинка')->first();

        if ($coverGroup) {
            ServiceParameterOption::withTrashed()
                ->where('group_id', $coverGroup->id)
                ->where('name', 'Сірий')
                ->each(fn ($opt) => $opt->restore());
        }
    }
};
