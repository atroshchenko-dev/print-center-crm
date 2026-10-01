<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Shift "Інше" sort_order to 80
        DB::table('inventory_categories')
            ->where('name', 'Інше')
            ->update(['sort_order' => 80]);

        // 2. Create "Витратні матеріали" category if not exists
        $categoryId = DB::table('inventory_categories')
            ->where('name', 'Витратні матеріали')
            ->value('id');

        if (!$categoryId) {
            $categoryId = DB::table('inventory_categories')->insertGetId([
                'name' => 'Витратні матеріали',
                'sort_order' => 70,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Create default items if they do not exist
        $items = [
            [
                'name' => 'Тонер чорний',
                'unit' => 'шт',
                'min_quantity' => 2,
            ],
            [
                'name' => 'Тонер кольоровий',
                'unit' => 'шт',
                'min_quantity' => 2,
            ],
            [
                'name' => 'Фарба для різографа чорна',
                'unit' => 'шт',
                'min_quantity' => 2,
            ],
            [
                'name' => 'Фарба для різографа кольорова',
                'unit' => 'шт',
                'min_quantity' => 2,
            ],
            [
                'name' => 'Майстер-плівка для різографа',
                'unit' => 'шт',
                'min_quantity' => 2,
            ],
        ];

        $maxSort = DB::table('inventory_items')
            ->where('inventory_category_id', $categoryId)
            ->max('sort_order') ?? 0;

        foreach ($items as $index => $item) {
            $exists = DB::table('inventory_items')
                ->where('inventory_category_id', $categoryId)
                ->where('name', $item['name'])
                ->exists();

            if (!$exists) {
                DB::table('inventory_items')->insert([
                    'inventory_category_id' => $categoryId,
                    'name' => $item['name'],
                    'unit' => $item['unit'],
                    'min_quantity' => $item['min_quantity'],
                    'current_quantity' => 0,
                    'avg_cost' => 0,
                    'is_active' => true,
                    'sort_order' => $maxSort + $index + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $categoryId = DB::table('inventory_categories')
            ->where('name', 'Витратні матеріали')
            ->value('id');

        if ($categoryId) {
            DB::table('inventory_items')
                ->where('inventory_category_id', $categoryId)
                ->delete();

            DB::table('inventory_categories')
                ->where('id', $categoryId)
                ->delete();
        }

        DB::table('inventory_categories')
            ->where('name', 'Інше')
            ->update(['sort_order' => 70]);
    }
};
