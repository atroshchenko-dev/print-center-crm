<?php

declare(strict_types=1);

use App\Models\InventoryItem;
use App\Models\Service;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use Illuminate\Database\Migrations\Migration;

/**
 * Deactivate gray (Сірий) channel color options and inventory items.
 * Gray covers already removed — channels should follow.
 */
return new class extends Migration
{
    private const CHANNEL_SIZES = ['5', '7', '10', '13', '16', '20', '24', '28', '32'];

    public function up(): void
    {
        $service = Service::where('name', 'Палітурка тверда')->first();
        if ($service) {
            $colorGroup = ServiceParameterGroup::where('service_id', $service->id)
                ->where('name', 'Колір')
                ->first();

            if ($colorGroup) {
                foreach (self::CHANNEL_SIZES as $mm) {
                    ServiceParameterOption::where('group_id', $colorGroup->id)
                        ->where('name', "Канал {$mm}мм: Сірий")
                        ->update(['is_active' => false]);
                }
            }
        }

        // Deactivate inventory items
        foreach (self::CHANNEL_SIZES as $mm) {
            InventoryItem::where('name', "Канал {$mm}мм (Сірий)")
                ->update(['is_active' => false]);
        }
    }

    public function down(): void
    {
        $service = Service::where('name', 'Палітурка тверда')->first();
        if ($service) {
            $colorGroup = ServiceParameterGroup::where('service_id', $service->id)
                ->where('name', 'Колір')
                ->first();

            if ($colorGroup) {
                foreach (self::CHANNEL_SIZES as $mm) {
                    ServiceParameterOption::where('group_id', $colorGroup->id)
                        ->where('name', "Канал {$mm}мм: Сірий")
                        ->update(['is_active' => true]);
                }
            }
        }

        foreach (self::CHANNEL_SIZES as $mm) {
            InventoryItem::where('name', "Канал {$mm}мм (Сірий)")
                ->update(['is_active' => true]);
        }
    }
};
