<?php

namespace Database\Seeders;

use App\Models\Material;
use Illuminate\Database\Seeder;

class MaterialSeeder extends Seeder
{
    public function run(): void
    {
        $materials = [
            ['name' => 'Ч/Б друк (вартість кліку)',       'counter_type' => 'bw',    'click_cost' => 0.3700],
            ['name' => 'Кольоровий друк (вартість кліку)', 'counter_type' => 'color', 'click_cost' => 2.0400],
            ['name' => 'Ризограф (вартість кліку)',        'counter_type' => 'riso',  'click_cost' => 0.0200],
        ];

        foreach ($materials as $mat) {
            Material::firstOrCreate(
                ['counter_type' => $mat['counter_type']],
                [
                    'name'       => $mat['name'],
                    'click_cost' => $mat['click_cost'],
                    'is_active'  => true,
                ]
            );
        }
    }
}
