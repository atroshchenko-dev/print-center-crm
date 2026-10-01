<?php

declare(strict_types=1);

use App\Models\InventoryItem;
use App\Models\Service;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use Illuminate\Database\Migrations\Migration;

/**
 * Deactivate gray (Сірий) cover for hard binding — too expensive (134.60₴ vs 105.30₴).
 * Only red and blue covers remain available.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Deactivate the constructor option
        $service = Service::where('name', 'Палітурка тверда')->first();
        if ($service) {
            $coverGroup = ServiceParameterGroup::where('service_id', $service->id)
                ->where('name', 'Обкладинка')
                ->first();

            if ($coverGroup) {
                ServiceParameterOption::where('group_id', $coverGroup->id)
                    ->where('name', 'Сірий')
                    ->update(['is_active' => false]);
            }
        }

        // Deactivate the inventory item
        InventoryItem::where('name', 'Обкладинка тверда (Сірий)')
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        $service = Service::where('name', 'Палітурка тверда')->first();
        if ($service) {
            $coverGroup = ServiceParameterGroup::where('service_id', $service->id)
                ->where('name', 'Обкладинка')
                ->first();

            if ($coverGroup) {
                ServiceParameterOption::where('group_id', $coverGroup->id)
                    ->where('name', 'Сірий')
                    ->update(['is_active' => true]);
            }
        }

        InventoryItem::where('name', 'Обкладинка тверда (Сірий)')
            ->update(['is_active' => true]);
    }
};
