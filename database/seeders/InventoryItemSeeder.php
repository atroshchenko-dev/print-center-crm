<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use Illuminate\Database\Seeder;

class InventoryItemSeeder extends Seeder
{
    public function run(): void
    {
        $categories = InventoryCategory::pluck('id', 'name');

        $items = [
            // ─── Плівки для ламінування ─────────────────────
            'Плівки для ламінування' => [
                ['name' => 'Плівка А4 100 мкм (глянець)', 'unit' => 'шт', 'min_quantity' => 50],
                ['name' => 'Плівка А4 100 мкм (мат)',     'unit' => 'шт', 'min_quantity' => 50],
                ['name' => 'Плівка А4 200 мкм (глянець)', 'unit' => 'шт', 'min_quantity' => 50],
                ['name' => 'Плівка А3 100 мкм (глянець)', 'unit' => 'шт', 'min_quantity' => 20],
                ['name' => 'Плівка А3 100 мкм (мат)',     'unit' => 'шт', 'min_quantity' => 20],
                ['name' => 'Плівка А3 200 мкм (глянець)', 'unit' => 'шт', 'min_quantity' => 20],
            ],

            // ─── Папір ──────────────────────────────────────
            'Папір' => [
                // Звичайний білий
                ['name' => 'Папір А4 80 г/м²',  'unit' => 'аркуш', 'min_quantity' => 500, 'subcategory' => PaperSubcategorySeeder::REGULAR],
                ['name' => 'Папір А3 80 г/м²',  'unit' => 'аркуш', 'min_quantity' => 200, 'subcategory' => PaperSubcategorySeeder::REGULAR],
                ['name' => 'Папір А4 160 г/м²', 'unit' => 'аркуш', 'min_quantity' => 100, 'subcategory' => PaperSubcategorySeeder::REGULAR],
                ['name' => 'Папір А4 200 г/м²', 'unit' => 'аркуш', 'min_quantity' => 50,  'subcategory' => PaperSubcategorySeeder::REGULAR],
                ['name' => 'Папір А3 160 г/м²', 'unit' => 'аркуш', 'min_quantity' => 100, 'subcategory' => PaperSubcategorySeeder::REGULAR],
                ['name' => 'Папір А3 200 г/м²', 'unit' => 'аркуш', 'min_quantity' => 50,  'subcategory' => PaperSubcategorySeeder::REGULAR],

                // Пастельний кольоровий
                ['name' => 'Папір А3 160 г/м² (пастельний рожевий)',    'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COLORED],
                ['name' => 'Папір А3 160 г/м² (пастельний жовтий)',     'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COLORED],
                ['name' => 'Папір А3 160 г/м² (пастельний зелений)',    'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COLORED],
                ['name' => 'Папір А3 160 г/м² (пастельний блакитний)',  'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COLORED],
                ['name' => 'Папір А4 160 г/м² (пастельний рожевий)',    'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COLORED],
                ['name' => 'Папір А4 160 г/м² (пастельний жовтий)',     'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COLORED],
                ['name' => 'Папір А4 160 г/м² (пастельний зелений)',    'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COLORED],
                ['name' => 'Папір А4 160 г/м² (пастельний блакитний)',  'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COLORED],

                // Крейдований — глянець і мат рахуються окремо
                ['name' => 'Папір А4 150 г/м² (крейдований, глянець)', 'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COATED],
                ['name' => 'Папір А4 250 г/м² (крейдований, глянець)', 'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COATED],
                ['name' => 'Папір А4 300 г/м² (крейдований, глянець)', 'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COATED],
                ['name' => 'Папір А3 150 г/м² (крейдований, глянець)', 'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COATED],
                ['name' => 'Папір А3 250 г/м² (крейдований, глянець)', 'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COATED],
                ['name' => 'Папір А3 300 г/м² (крейдований, глянець)', 'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COATED],
                ['name' => 'Папір А4 150 г/м² (крейдований, мат)', 'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COATED],
                ['name' => 'Папір А4 250 г/м² (крейдований, мат)', 'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COATED],
                ['name' => 'Папір А4 300 г/м² (крейдований, мат)', 'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COATED],
                ['name' => 'Папір А3 150 г/м² (крейдований, мат)', 'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COATED],
                ['name' => 'Папір А3 250 г/м² (крейдований, мат)', 'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COATED],
                ['name' => 'Папір А3 300 г/м² (крейдований, мат)', 'unit' => 'аркуш', 'min_quantity' => 50, 'subcategory' => PaperSubcategorySeeder::COATED],

                // Дизайнерський картон А4 (для візиток)
                ['name' => 'Картон А4 дизайнерський (Стардрім опал)',   'unit' => 'аркуш', 'min_quantity' => 20, 'subcategory' => PaperSubcategorySeeder::DESIGNER],
                ['name' => 'Картон А4 дизайнерський (Мальмеро сніжок)', 'unit' => 'аркуш', 'min_quantity' => 20, 'subcategory' => PaperSubcategorySeeder::DESIGNER],
                ['name' => 'Картон А4 дизайнерський (Білий льон)',      'unit' => 'аркуш', 'min_quantity' => 20, 'subcategory' => PaperSubcategorySeeder::DESIGNER],
                ['name' => 'Картон А4 дизайнерський (Жовтий льон)',     'unit' => 'аркуш', 'min_quantity' => 20, 'subcategory' => PaperSubcategorySeeder::DESIGNER],
            ],

            // ─── Пружини ────────────────────────────────────
            'Пружини' => [
                ['name' => 'Пружина 6 мм',  'unit' => 'шт', 'min_quantity' => 50],
                ['name' => 'Пружина 8 мм',  'unit' => 'шт', 'min_quantity' => 50],
                ['name' => 'Пружина 10 мм', 'unit' => 'шт', 'min_quantity' => 50],
                ['name' => 'Пружина 12 мм', 'unit' => 'шт', 'min_quantity' => 50],
                ['name' => 'Пружина 14 мм', 'unit' => 'шт', 'min_quantity' => 30],
                ['name' => 'Пружина 16 мм', 'unit' => 'шт', 'min_quantity' => 30],
                ['name' => 'Пружина 19 мм', 'unit' => 'шт', 'min_quantity' => 20],
                ['name' => 'Пружина 22 мм', 'unit' => 'шт', 'min_quantity' => 20],
                ['name' => 'Пружина 25 мм', 'unit' => 'шт', 'min_quantity' => 20],
                ['name' => 'Пружина 28 мм', 'unit' => 'шт', 'min_quantity' => 10],
                ['name' => 'Пружина 32 мм', 'unit' => 'шт', 'min_quantity' => 10],
                ['name' => 'Пружина 38 мм', 'unit' => 'шт', 'min_quantity' => 10],
                ['name' => 'Пружина 45 мм', 'unit' => 'шт', 'min_quantity' => 10],
                ['name' => 'Пружина 51 мм', 'unit' => 'шт', 'min_quantity' => 10],
            ],

            // ─── Обкладинки ─────────────────────────────────
            'Обкладинки' => [
                ['name' => 'Обкладинка прозора PVC А4, 150 мкм', 'unit' => 'шт', 'min_quantity' => 50],
                ['name' => 'Обкладинка картонна А4, 230 г/м² (чорна)', 'unit' => 'шт', 'min_quantity' => 50],
            ],

            // ─── Витратні матеріали ─────────────────────────
            'Витратні матеріали' => [
                ['name' => 'Тонер Develop ineo+ 220 (Black)', 'unit' => 'шт', 'min_quantity' => 2],
                ['name' => 'Тонер Develop ineo+ 220 (Cyan)', 'unit' => 'шт', 'min_quantity' => 2],
                ['name' => 'Тонер Develop ineo+ 220 (Magenta)', 'unit' => 'шт', 'min_quantity' => 2],
                ['name' => 'Тонер Develop ineo+ 220 (Yellow)', 'unit' => 'шт', 'min_quantity' => 2],
                ['name' => 'Тонер Develop ineo+ 251i (Black)', 'unit' => 'шт', 'min_quantity' => 2],
                ['name' => 'Тонер Develop ineo+ 251i (Cyan)', 'unit' => 'шт', 'min_quantity' => 2],
                ['name' => 'Тонер Develop ineo+ 251i (Magenta)', 'unit' => 'шт', 'min_quantity' => 2],
                ['name' => 'Тонер Develop ineo+ 251i (Yellow)', 'unit' => 'шт', 'min_quantity' => 2],
                ['name' => 'Тонер Konica Minolta bizhub 283', 'unit' => 'шт', 'min_quantity' => 2],
                ['name' => 'Тонер Kyocera ECOSYS M4125idn', 'unit' => 'шт', 'min_quantity' => 2],
                ['name' => 'Фарба для різографа Ricoh DD4450', 'unit' => 'шт', 'min_quantity' => 2],
                ['name' => 'Майстер-плівка для різографа Ricoh DD4450', 'unit' => 'шт', 'min_quantity' => 2],
            ],
        ];

        $total = 0;

        foreach ($items as $categoryName => $categoryItems) {
            $categoryId = $categories->get($categoryName);
            if (! $categoryId) {
                $this->command->warn("⚠ Category '{$categoryName}' not found, skipping.");

                continue;
            }

            foreach ($categoryItems as $item) {
                InventoryItem::firstOrCreate(
                    [
                        'inventory_category_id' => $categoryId,
                        'name'                  => $item['name'],
                    ],
                    [
                        'unit'             => $item['unit'],
                        'current_quantity' => 0,
                        'avg_cost'         => 0,
                        'min_quantity'     => $item['min_quantity'],
                        'subcategory'      => $item['subcategory'] ?? null,
                        'is_active'        => true,
                    ]
                );
                $total++;
            }
        }

        $this->command->info("✅ Inventory items seeded: {$total} items.");

        // ─── Paper conversion mapping: A3 → A4 (ratio 2.0) ───────
        $this->seedConversionMapping();
    }

    /**
     * Set convertible_from_id + conversion_ratio for A4 paper items.
     * Each A4 paper type can be obtained by cutting its A3 counterpart (1 A3 = 2 A4).
     */
    private function seedConversionMapping(): void
    {
        // Pairs: [A4 name, A3 name]
        $pairs = [
            ['Папір А4 80 г/м²',  'Папір А3 80 г/м²'],
            ['Папір А4 160 г/м²', 'Папір А3 160 г/м²'],
            ['Папір А4 200 г/м²', 'Папір А3 200 г/м²'],
            ['Папір А4 160 г/м² (пастельний рожевий)',   'Папір А3 160 г/м² (пастельний рожевий)'],
            ['Папір А4 160 г/м² (пастельний жовтий)',    'Папір А3 160 г/м² (пастельний жовтий)'],
            ['Папір А4 160 г/м² (пастельний зелений)',   'Папір А3 160 г/м² (пастельний зелений)'],
            ['Папір А4 160 г/м² (пастельний блакитний)', 'Папір А3 160 г/м² (пастельний блакитний)'],
            ['Папір А4 150 г/м² (крейдований, глянець)', 'Папір А3 150 г/м² (крейдований, глянець)'],
            ['Папір А4 250 г/м² (крейдований, глянець)', 'Папір А3 250 г/м² (крейдований, глянець)'],
            ['Папір А4 300 г/м² (крейдований, глянець)', 'Папір А3 300 г/м² (крейдований, глянець)'],
            ['Папір А4 150 г/м² (крейдований, мат)', 'Папір А3 150 г/м² (крейдований, мат)'],
            ['Папір А4 250 г/м² (крейдований, мат)', 'Папір А3 250 г/м² (крейдований, мат)'],
            ['Папір А4 300 г/м² (крейдований, мат)', 'Папір А3 300 г/м² (крейдований, мат)'],
        ];

        $mapped = 0;
        foreach ($pairs as [$a4Name, $a3Name]) {
            $a4 = InventoryItem::where('name', $a4Name)->first();
            $a3 = InventoryItem::where('name', $a3Name)->first();

            if ($a4 && $a3) {
                $a4->update([
                    'convertible_from_id' => $a3->id,
                    'conversion_ratio'    => 2.00,
                ]);
                $mapped++;
            }
        }

        $this->command->info("✂️  Paper conversion pairs mapped: {$mapped} (A3 → A4, ratio 2.0).");
    }
}
