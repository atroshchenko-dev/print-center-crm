<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ScanningServiceSeeder extends Seeder
{
    public function run(): void
    {
        $category = ServiceCategory::where('name', 'Сканування')->firstOrFail();

        $services = [
            [
                'name'                  => 'Сканування документів А4',
                'type'                  => 'static',
                'base_price_commercial' => 6.00,
                'base_price_cost'       => 0.50,
                'counter_type'          => 'none',
                'clicks_per_unit'       => 0,
                'is_active'             => true,
            ],
            [
                'name'                  => 'Сканування документів А3',
                'type'                  => 'static',
                'base_price_commercial' => 10.00,
                'base_price_cost'       => 1.00,
                'counter_type'          => 'none',
                'clicks_per_unit'       => 0,
                'is_active'             => true,
            ],
        ];

        foreach ($services as $service) {
            Service::updateOrCreate(
                [
                    'service_category_id' => $category->id,
                    'name'                => $service['name'],
                ],
                $service
            );
        }
    }
}
