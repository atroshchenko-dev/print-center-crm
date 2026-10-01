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

        // Delete the color risograph ink item
        InventoryItem::where('inventory_category_id', $category->id)
            ->where('name', 'Фарба для різографа Ricoh DD4450 (кольорова)')
            ->forceDelete();

        // Rename the black risograph ink to generic since it's the only one
        InventoryItem::where('inventory_category_id', $category->id)
            ->where('name', 'Фарба для різографа Ricoh DD4450 (чорна)')
            ->update(['name' => 'Фарба для різографа Ricoh DD4450']);
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

        // Rename back to (чорна)
        InventoryItem::where('inventory_category_id', $category->id)
            ->where('name', 'Фарба для різографа Ricoh DD4450')
            ->update(['name' => 'Фарба для різографа Ricoh DD4450 (чорна)']);

        // Re-create color risograph ink
        InventoryItem::firstOrCreate(
            [
                'inventory_category_id' => $category->id,
                'name' => 'Фарба для різографа Ricoh DD4450 (кольорова)'
            ],
            [
                'unit' => 'шт',
                'current_quantity' => 0,
                'avg_cost' => 0,
                'min_quantity' => 2,
                'is_active' => true,
            ]
        );
    }
};
