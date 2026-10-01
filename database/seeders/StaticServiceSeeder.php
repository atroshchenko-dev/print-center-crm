<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class StaticServiceSeeder extends Seeder
{
    public function run(): void
    {
        $categories = ServiceCategory::whereNull('deleted_at')->pluck('id', 'name');

        $services = [
            // ─── Ламінування ────────────────────────────────
            'Ламінування' => [
                ['name' => 'Ламінування А4 100 мкм (глянець)', 'base_price_commercial' => 27.00, 'base_price_cost' => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0],
                ['name' => 'Ламінування А4 100 мкм (мат)',     'base_price_commercial' => 30.00, 'base_price_cost' => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0],
                ['name' => 'Ламінування А4 200 мкм (глянець)', 'base_price_commercial' => 32.00, 'base_price_cost' => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0],
                ['name' => 'Ламінування А3 100 мкм (глянець)', 'base_price_commercial' => 35.00, 'base_price_cost' => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0],
                ['name' => 'Ламінування А3 100 мкм (мат)',     'base_price_commercial' => 40.00, 'base_price_cost' => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0],
            ],
        ];

        $count = 0;

        foreach ($services as $categoryName => $categoryServices) {
            $categoryId = $categories->get($categoryName);
            if (! $categoryId) {
                $this->command->warn("⚠ Category '{$categoryName}' not found, skipping.");
                continue;
            }

            foreach ($categoryServices as $service) {
                Service::updateOrCreate(
                    [
                        'service_category_id' => $categoryId,
                        'name'                => $service['name'],
                    ],
                    [
                        'type'                  => $service['type'] ?? 'static',
                        'base_price_commercial' => $service['base_price_commercial'],
                        'base_price_cost'       => $service['base_price_cost'],
                        'counter_type'          => $service['counter_type'],
                        'clicks_per_unit'       => $service['clicks_per_unit'],
                        'is_active'             => true,
                    ]
                );
                $count++;
            }
        }

        // ─── RISO (Тиражування) ─────────────────────────
        $risoCatId = $categories->get('Тиражування');
        if ($risoCatId) {
            Service::updateOrCreate(
                ['service_category_id' => $risoCatId, 'name' => 'Тиражування (RISO)'],
                [
                    'type'                  => 'riso',
                    'base_price_commercial' => 0,
                    'base_price_cost'       => 0,
                    'counter_type'          => 'riso',
                    'clicks_per_unit'       => 0,
                    'is_active'             => true,
                ]
            );
            $count++;
        }

        $this->command->info("✅ Static services seeded: {$count} services.");
    }
}
