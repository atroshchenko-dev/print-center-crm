<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ServiceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Чорно-білий друк',  'available_for' => ['internal', 'commercial']],
            ['name' => 'Кольоровий друк',    'available_for' => ['internal', 'commercial']],
            ['name' => 'Ламінування',        'available_for' => ['internal', 'commercial']],
            ['name' => 'Сканування',         'available_for' => ['internal', 'commercial']],
            ['name' => 'Палітурка тверда',   'available_for' => ['internal', 'commercial']],
            ['name' => 'Палітурка м\'яка',   'available_for' => ['internal', 'commercial']],
            ['name' => 'Брошури',            'available_for' => ['internal']],
            ['name' => 'Дипломи/Додатки',    'available_for' => ['internal']],
            ['name' => 'Тиражування',        'available_for' => ['internal']],
            ['name' => 'Візитівки',          'available_for' => ['internal']],
            ['name' => 'Розрізання паперу',  'available_for' => ['internal'], 'is_active' => false],
        ];

        foreach ($categories as $index => $cat) {
            ServiceCategory::updateOrCreate(
                ['name' => $cat['name']],
                [
                    'sort_order'    => ($index + 1) * 10,
                    'is_active'     => $cat['is_active'] ?? true,
                    'available_for' => $cat['available_for'],
                ]
            );
        }

        // Soft-delete legacy categories that are no longer in the active list
        $activeNames = array_column($categories, 'name');
        ServiceCategory::whereNotIn('name', $activeNames)
            ->whereNull('deleted_at')
            ->each(fn (ServiceCategory $c) => $c->delete());
    }
}
