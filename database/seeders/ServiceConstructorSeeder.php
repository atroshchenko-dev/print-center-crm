<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use Illuminate\Database\Seeder;

/**
 * Constructor services seeder — Палітурка м'яка (пружина).
 *
 * Creates a constructor service with 2 parameter groups:
 * 1. Cover (обкладинка) — radio, linked to inventory (0 markup, included in spring price)
 * 2. Spring (пружина) — radio, linked to inventory, commercial price as markup
 *
 * Commercial price = spring option price_markup (includes cover + work).
 */
class ServiceConstructorSeeder extends Seeder
{
    public function run(): void
    {
        $category = ServiceCategory::where('name', "Палітурка м'яка")->first();
        if (! $category) {
            $this->command->warn("⚠ Category 'Палітурка м'яка' not found, skipping.");
            return;
        }

        // ─── Create service ──────────────────────────────
        $service = Service::updateOrCreate(
            [
                'service_category_id' => $category->id,
                'name'                => 'Палітурка на пружині',
            ],
            [
                'type'                  => 'constructor',
                'base_price_commercial' => 0,
                'base_price_cost'       => 0,
                'counter_type'          => 'none',
                'clicks_per_unit'       => 0,
                'is_active'             => true,
            ]
        );

        // ─── Group 1: Cover (checkbox — optional, both ON by default) ──
        $groupCover = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $service->id, 'name' => 'Обкладинка'],
            [
                'ui_type'     => 'checkbox',
                'is_required' => false,
                'sort_order'  => 10,
            ]
        );

        $coverTransparent = InventoryItem::where('name', 'Обкладинка прозора PVC А4, 150 мкм')->first();
        $coverCardboard   = InventoryItem::where('name', 'Обкладинка картонна А4, 230 г/м² (чорна)')->first();

        // Передня обкладинка (прозора PVC)
        ServiceParameterOption::updateOrCreate(
            ['group_id' => $groupCover->id, 'name' => 'Передня (прозора PVC)'],
            [
                'price_markup'      => 0,
                'counter_type'      => 'none',
                'clicks_per_unit'   => 0,
                'inventory_item_id' => $coverTransparent?->id,
                'inventory_qty'     => 1,
                'sort_order'        => 10,
                'is_active'         => true,
            ]
        );

        // Задня обкладинка (картон)
        ServiceParameterOption::updateOrCreate(
            ['group_id' => $groupCover->id, 'name' => 'Задня (картон)'],
            [
                'price_markup'      => 0,
                'counter_type'      => 'none',
                'clicks_per_unit'   => 0,
                'inventory_item_id' => $coverCardboard?->id,
                'inventory_qty'     => 1,
                'sort_order'        => 20,
                'is_active'         => true,
            ]
        );

        // Clean up old combined option from previous seeder runs
        ServiceParameterOption::where('group_id', $groupCover->id)
            ->whereIn('name', ['Прозора + картон', 'Картон + картон'])
            ->whereNull('deleted_at')
            ->each(fn ($opt) => $opt->delete());

        // ─── Group 2: Spring ─────────────────────────────
        $groupSpring = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $service->id, 'name' => 'Пружина'],
            [
                'ui_type'     => 'radio',
                'is_required' => true,
                'sort_order'  => 20,
            ]
        );

        // Commercial prices from client
        $springs = [
            ['size' => '6 мм',  'pages' => 25,  'commercial' => 45.00],
            ['size' => '8 мм',  'pages' => 45,  'commercial' => 50.00],
            ['size' => '10 мм', 'pages' => 65,  'commercial' => 55.00],
            ['size' => '12 мм', 'pages' => 90,  'commercial' => 60.00],
            ['size' => '14 мм', 'pages' => 100, 'commercial' => 70.00],
            ['size' => '16 мм', 'pages' => 120, 'commercial' => 80.00],
            ['size' => '19 мм', 'pages' => 150, 'commercial' => 90.00],
            ['size' => '22 мм', 'pages' => 180, 'commercial' => 100.00],
            ['size' => '25 мм', 'pages' => 220, 'commercial' => 110.00],
            ['size' => '28 мм', 'pages' => 240, 'commercial' => 120.00],
            ['size' => '32 мм', 'pages' => 280, 'commercial' => 130.00],
            ['size' => '38 мм', 'pages' => 340, 'commercial' => 140.00],
            ['size' => '45 мм', 'pages' => 410, 'commercial' => 150.00],
            ['size' => '51 мм', 'pages' => 450, 'commercial' => 160.00],
        ];

        foreach ($springs as $index => $spring) {
            $invItem = InventoryItem::where('name', "Пружина {$spring['size']}")->first();

            ServiceParameterOption::updateOrCreate(
                ['group_id' => $groupSpring->id, 'name' => "{$spring['size']} (до {$spring['pages']} арк.)"],
                [
                    'price_markup'      => $spring['commercial'],
                    'counter_type'      => 'none',
                    'clicks_per_unit'   => 0,
                    'inventory_item_id' => $invItem?->id,
                    'inventory_qty'     => 1,
                    'sort_order'        => ($index + 1) * 10,
                    'is_active'         => true,
                ]
            );
        }

        $this->command->info('✅ Constructor seeded: "Палітурка на пружині" — 2 groups, '
            . (count($springs) + 2) . ' options.');

        // Auto-flush cached reference data
        app(\App\Services\ReferenceDataService::class)->flush();
    }
}
