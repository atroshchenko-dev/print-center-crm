<?php

namespace Database\Seeders;

use App\Models\RisoPriceTier;
use Illuminate\Database\Seeder;

class RisoPriceTierSeeder extends Seeder
{
    /**
     * Riso cost per copy (A3, 1 side) based on real costs:
     * - Master roll: 3564 UAH / 200 frames = 17.82 UAH per frame
     * - Ink: 264.96 UAH / 5000 copies = 0.053 UAH per copy
     * - Formula: (17.82 / quantity) + 0.053
     * - Paper cost is NOT included here (added via config('riso.paper_cost_a3'))
     */
    public function run(): void
    {
        $tiers = [
            ['min_qty' =>  50, 'max_qty' =>  99, 'cost_per_copy' => 0.4100],
            ['min_qty' => 100, 'max_qty' => 149, 'cost_per_copy' => 0.2300],
            ['min_qty' => 150, 'max_qty' => 199, 'cost_per_copy' => 0.1700],
            ['min_qty' => 200, 'max_qty' => 249, 'cost_per_copy' => 0.1400],
            ['min_qty' => 250, 'max_qty' => 299, 'cost_per_copy' => 0.1200],
            ['min_qty' => 300, 'max_qty' => 349, 'cost_per_copy' => 0.1100],
            ['min_qty' => 350, 'max_qty' => 399, 'cost_per_copy' => 0.1000],
            ['min_qty' => 400, 'max_qty' => 449, 'cost_per_copy' => 0.1000],
            ['min_qty' => 450, 'max_qty' => 499, 'cost_per_copy' => 0.0900],
            ['min_qty' => 500, 'max_qty' => null, 'cost_per_copy' => 0.0900],
        ];

        foreach ($tiers as $tier) {
            RisoPriceTier::updateOrCreate(
                ['min_qty' => $tier['min_qty']],
                [
                    'max_qty'       => $tier['max_qty'],
                    'cost_per_copy' => $tier['cost_per_copy'],
                ]
            );
        }
    }
}
