<?php

namespace Database\Seeders;

use App\Models\InventoryCategory;
use Illuminate\Database\Seeder;

class InventoryCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Папір',
            'Пружини',
            'Плівки для ламінування',
            'Обкладинки',
            'Тверда палітурка (комплектні)',
            'Тверда палітурка (канали + обкладинки)',
            'Витратні матеріали',
            'Інше',
        ];

        foreach ($categories as $index => $name) {
            InventoryCategory::firstOrCreate(
                ['name' => $name],
                [
                    'sort_order' => ($index + 1) * 10,
                    'is_active'  => true,
                ]
            );
        }
    }
}
