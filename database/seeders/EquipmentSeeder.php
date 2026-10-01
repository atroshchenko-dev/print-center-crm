<?php

namespace Database\Seeders;

use App\Models\Equipment;
use Illuminate\Database\Seeder;

class EquipmentSeeder extends Seeder
{
    public function run(): void
    {
        $equipment = [
            ['name' => 'Develop ineo+ 220',           'type' => 'color', 'serial_number' => null],
            ['name' => 'Develop ineo+ 251i',           'type' => 'color', 'serial_number' => null],
            ['name' => 'Konica Minolta bizhub 283',    'type' => 'bw',    'serial_number' => null],
            ['name' => 'Kyocera ECOSYS M4125idn',     'type' => 'bw',    'serial_number' => null],
            ['name' => 'Ricoh DD4450',                 'type' => 'riso',  'serial_number' => null],
        ];

        foreach ($equipment as $item) {
            Equipment::firstOrCreate(
                ['name' => $item['name']],
                [
                    'type'            => $item['type'],
                    'serial_number'   => $item['serial_number'],
                    'initial_counter' => 0,
                    'is_active'       => true,
                ]
            );
        }
    }
}
