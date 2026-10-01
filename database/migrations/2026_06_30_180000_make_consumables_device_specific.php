<?php

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $category = InventoryCategory::where('name', 'Витратні матеріали')->first();
        if (!$category) {
            return;
        }

        // Rename the 5 existing generic items to specific ones
        $renames = [
            'Тонер чорний' => 'Тонер Develop ineo+ 220 (Black)',
            'Тонер кольоровий' => 'Тонер Develop ineo+ 220 (Cyan)',
            'Фарба для різографа чорна' => 'Фарба для різографа Ricoh DD4450 (чорна)',
            'Фарба для різографа кольорова' => 'Фарба для різографа Ricoh DD4450 (кольорова)',
            'Майстер-плівка для різографа' => 'Майстер-плівка для різографа Ricoh DD4450',
        ];

        foreach ($renames as $oldName => $newName) {
            InventoryItem::where('inventory_category_id', $category->id)
                ->where('name', $oldName)
                ->update(['name' => $newName]);
        }

        // Insert the remaining 8 specific items
        $newItems = [
            ['name' => 'Тонер Develop ineo+ 220 (Magenta)', 'unit' => 'шт', 'min_quantity' => 2],
            ['name' => 'Тонер Develop ineo+ 220 (Yellow)',  'unit' => 'шт', 'min_quantity' => 2],
            ['name' => 'Тонер Develop ineo+ 251i (Black)',   'unit' => 'шт', 'min_quantity' => 2],
            ['name' => 'Тонер Develop ineo+ 251i (Cyan)',    'unit' => 'шт', 'min_quantity' => 2],
            ['name' => 'Тонер Develop ineo+ 251i (Magenta)', 'unit' => 'шт', 'min_quantity' => 2],
            ['name' => 'Тонер Develop ineo+ 251i (Yellow)',  'unit' => 'шт', 'min_quantity' => 2],
            ['name' => 'Тонер Konica Minolta bizhub 283',    'unit' => 'шт', 'min_quantity' => 2],
            ['name' => 'Тонер Kyocera ECOSYS M4125idn',     'unit' => 'шт', 'min_quantity' => 2],
        ];

        foreach ($newItems as $item) {
            InventoryItem::firstOrCreate(
                [
                    'inventory_category_id' => $category->id,
                    'name' => $item['name']
                ],
                [
                    'unit' => $item['unit'],
                    'current_quantity' => 0,
                    'avg_cost' => 0,
                    'min_quantity' => $item['min_quantity'],
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $category = InventoryCategory::where('name', 'Витратні матеріали')->first();
        if (!$category) {
            return;
        }

        // Delete the 8 specific items
        $itemsToDelete = [
            'Тонер Develop ineo+ 220 (Magenta)',
            'Тонер Develop ineo+ 220 (Yellow)',
            'Тонер Develop ineo+ 251i (Black)',
            'Тонер Develop ineo+ 251i (Cyan)',
            'Тонер Develop ineo+ 251i (Magenta)',
            'Тонер Develop ineo+ 251i (Yellow)',
            'Тонер Konica Minolta bizhub 283',
            'Тонер Kyocera ECOSYS M4125idn',
        ];

        InventoryItem::where('inventory_category_id', $category->id)
            ->whereIn('name', $itemsToDelete)
            ->forceDelete();

        // Rename the 5 items back to generic
        $reverts = [
            'Тонер Develop ineo+ 220 (Black)' => 'Тонер чорний',
            'Тонер Develop ineo+ 220 (Cyan)' => 'Тонер кольоровий',
            'Фарба для різографа Ricoh DD4450 (чорна)' => 'Фарба для різографа чорна',
            'Фарба для різографа Ricoh DD4450 (кольорова)' => 'Фарба для різографа кольорова',
            'Майстер-плівка для різографа Ricoh DD4450' => 'Майстер-плівка для різографа',
        ];

        foreach ($reverts as $oldName => $newName) {
            InventoryItem::where('inventory_category_id', $category->id)
                ->where('name', $oldName)
                ->update(['name' => $newName]);
        }
    }
};
