<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Services\ReferenceDataService;
use Illuminate\Database\Seeder;

/**
 * Палітурка тверда — Constructor Seeder.
 *
 * Two binding types:
 *   1. Комплектна (one-piece) — 8 sizes × 3 colors = 24 inventory items
 *   2. Канал + Обкладинка — 9 sizes × 2 colors + 2 covers = 20 inventory items
 *
 * UI flow:
 *   Step 1: Тип → [Комплектна] [Канал + Обкладинка]
 *   Step 2: Розмір → filtered by type (8 or 9 options)
 *   Step 3: Колір → [Червоний] [Синій] [Чорний] — filtered by size, linked to inventory
 *   Step 4: Обкладинка → [Червона] [Синя] — only for Канал type
 *
 * Prices from spreadsheet are COST prices (собівартість).
 * Commercial prices based on page-count tiers (до 145→230, до 185→250,
 * до 215→270, до 255→290, до 300→300 грн/екз.).
 * cost_markup is auto-calculated from inventory avg_cost × qty.
 */
class HardBindingConstructorSeeder extends Seeder
{
    // ─── Cost data from client spreadsheet ──────────────

    /** @var array<array{mm: string, pages: string, cost: float, commercial: float}> */
    private const COMPLETE_SIZES = [
        ['mm' => '3.5',  'pages' => '0-35',    'cost' => 170.88, 'commercial' => 230.00],
        ['mm' => '7',    'pages' => '36-70',    'cost' => 170.88, 'commercial' => 230.00],
        ['mm' => '10.5', 'pages' => '71-105',   'cost' => 182.25, 'commercial' => 230.00],
        ['mm' => '14',   'pages' => '106-140',  'cost' => 182.25, 'commercial' => 230.00],
        ['mm' => '17.5', 'pages' => '141-175',  'cost' => 207.35, 'commercial' => 250.00],
        ['mm' => '21',   'pages' => '176-210',  'cost' => 207.35, 'commercial' => 270.00],
        ['mm' => '24.5', 'pages' => '211-245',  'cost' => 218.73, 'commercial' => 290.00],
        ['mm' => '28',   'pages' => '246-280',  'cost' => 230.15, 'commercial' => 300.00],
    ];

    /** @var array<array{mm: string, pages: string, cost: float, commercial: float}> */
    private const CHANNEL_SIZES = [
        ['mm' => '5',  'pages' => '0-35',    'cost' => 61.42, 'commercial' => 230.00],
        ['mm' => '7',  'pages' => '36-60',   'cost' => 61.42, 'commercial' => 230.00],
        ['mm' => '10', 'pages' => '61-90',   'cost' => 61.42, 'commercial' => 230.00],
        ['mm' => '13', 'pages' => '91-120',  'cost' => 61.42, 'commercial' => 230.00],
        ['mm' => '16', 'pages' => '121-150', 'cost' => 72.54, 'commercial' => 250.00],
        ['mm' => '20', 'pages' => '151-190', 'cost' => 72.54, 'commercial' => 270.00],
        ['mm' => '24', 'pages' => '191-220', 'cost' => 72.54, 'commercial' => 290.00],
        ['mm' => '28', 'pages' => '221-260', 'cost' => 72.54, 'commercial' => 300.00],
        ['mm' => '32', 'pages' => '261-300', 'cost' => 72.54, 'commercial' => 300.00],
    ];

    private const COLORS = ['Червоний', 'Синій'];

    private const COMPLETE_COLORS = ['Червоний', 'Синій', 'Чорний'];

    /** @var array<string, float> Cover cost by color */
    private const COVER_COSTS = [
        'Червоний' => 105.30,
        'Синій'    => 105.30,
    ];

    public function run(): void
    {
        $category = ServiceCategory::where('name', 'Палітурка тверда')->first();
        if (! $category) {
            $this->command->warn("⚠ Category 'Палітурка тверда' not found, skipping.");

            return;
        }

        // ─── Ensure inventory categories exist ───────────
        $invCatComplete = InventoryCategory::firstOrCreate(
            ['name' => 'Тверда палітурка (комплектні)'],
            ['sort_order' => 50, 'is_active' => true]
        );
        $invCatChannel = InventoryCategory::firstOrCreate(
            ['name' => 'Тверда палітурка (канали + обкладинки)'],
            ['sort_order' => 55, 'is_active' => true]
        );

        // ─── Create inventory items ─────────────────────
        $this->createInventoryItems($invCatComplete->id, $invCatChannel->id);

        // ─── Create constructor service ──────────────────
        $service = Service::updateOrCreate(
            [
                'service_category_id' => $category->id,
                'name'                => 'Палітурка тверда',
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

        // ─── Group 1: Тип (required) ────────────────────
        $typeGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $service->id, 'name' => 'Тип палітурки'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_large', 'is_required' => true, 'sort_order' => 10]
        );

        $typeComplete = ServiceParameterOption::updateOrCreate(
            ['group_id' => $typeGroup->id, 'name' => 'Комплектна (цільна)'],
            [
                'price_markup'      => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0,
                'inventory_item_id' => null, 'inventory_qty' => 0,
                'sort_order'        => 10, 'is_active' => true,
            ]
        );

        $typeChannel = ServiceParameterOption::updateOrCreate(
            ['group_id' => $typeGroup->id, 'name' => 'Канал + Обкладинка'],
            [
                'price_markup'      => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0,
                'inventory_item_id' => null, 'inventory_qty' => 0,
                'sort_order'        => 20, 'is_active' => true,
            ]
        );

        // ─── Group 2: Розмір (required, depends_on type) ─
        $sizeGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $service->id, 'name' => 'Розмір'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_small', 'is_required' => true, 'sort_order' => 20]
        );

        $sizeOpts = [];
        $sort = 10;

        // Complete sizes
        foreach (self::COMPLETE_SIZES as $size) {
            $label = "{$size['mm']} мм ({$size['pages']} стор.)";
            $sizeOpts["complete:{$size['mm']}"] = ServiceParameterOption::updateOrCreate(
                ['group_id' => $sizeGroup->id, 'name' => "Комплектна: {$label}"],
                [
                    'price_markup'      => $size['commercial'], 'counter_type' => 'none', 'clicks_per_unit' => 0,
                    'inventory_item_id' => null, 'inventory_qty' => 0,
                    'sort_order'        => $sort++, 'is_active' => true,
                    'depends_on'        => ['group_id' => $typeGroup->id, 'option_ids' => [$typeComplete->id]],
                ]
            );
        }

        // Channel sizes
        foreach (self::CHANNEL_SIZES as $size) {
            $label = "{$size['mm']} мм ({$size['pages']} стор.)";
            $sizeOpts["channel:{$size['mm']}"] = ServiceParameterOption::updateOrCreate(
                ['group_id' => $sizeGroup->id, 'name' => "Канал: {$label}"],
                [
                    'price_markup'      => $size['commercial'], 'counter_type' => 'none', 'clicks_per_unit' => 0,
                    'inventory_item_id' => null, 'inventory_qty' => 0,
                    'sort_order'        => $sort++, 'is_active' => true,
                    'depends_on'        => ['group_id' => $typeGroup->id, 'option_ids' => [$typeChannel->id]],
                ]
            );
        }

        // ─── Group 3: Колір (required, depends_on size) ──
        $colorGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $service->id, 'name' => 'Колір'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_large', 'is_required' => true, 'sort_order' => 30]
        );

        $sort = 10;

        // Colors for complete sizes — each linked to unique inventory item
        foreach (self::COMPLETE_SIZES as $size) {
            $sizeOptId = $sizeOpts["complete:{$size['mm']}"]->id;

            foreach (self::COMPLETE_COLORS as $color) {
                $invName = "Палітурка комплектна {$size['mm']}мм ({$color})";
                $invItem = InventoryItem::where('name', $invName)->first();

                ServiceParameterOption::updateOrCreate(
                    ['group_id' => $colorGroup->id, 'name' => "Комплектна {$size['mm']}мм: {$color}"],
                    [
                        'price_markup'      => 0,
                        'counter_type'      => 'none',
                        'clicks_per_unit'   => 0,
                        'inventory_item_id' => $invItem?->id,
                        'inventory_qty'     => 1,
                        'sort_order'        => $sort++,
                        'is_active'         => true,
                        'depends_on'        => ['group_id' => $sizeGroup->id, 'option_ids' => [$sizeOptId]],
                    ]
                );
            }
        }

        // Colors for channel sizes — each linked to unique inventory item
        // Group by color for cover depends_on matching
        $channelColorByColor = []; // 'Червоний' => [optId1, optId2, ...]
        foreach (self::CHANNEL_SIZES as $size) {
            $sizeOptId = $sizeOpts["channel:{$size['mm']}"]->id;

            foreach (self::COLORS as $color) {
                $invName = "Канал {$size['mm']}мм ({$color})";
                $invItem = InventoryItem::where('name', $invName)->first();

                $opt = ServiceParameterOption::updateOrCreate(
                    ['group_id' => $colorGroup->id, 'name' => "Канал {$size['mm']}мм: {$color}"],
                    [
                        'price_markup'      => 0,
                        'counter_type'      => 'none',
                        'clicks_per_unit'   => 0,
                        'inventory_item_id' => $invItem?->id,
                        'inventory_qty'     => 1,
                        'sort_order'        => $sort++,
                        'is_active'         => true,
                        'depends_on'        => ['group_id' => $sizeGroup->id, 'option_ids' => [$sizeOptId]],
                    ]
                );
                $channelColorByColor[$color][] = $opt->id;
            }
        }

        // ─── Group 4: Обкладинка (checkbox, REQUIRED for channel type, depends_on MATCHING channel color) ──
        // Each cover color depends only on channel options of the same color.
        // e.g. Cover "Червоний" → visible only when a red channel is selected.
        $coverGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $service->id, 'name' => 'Обкладинка'],
            [
                'ui_type'     => 'checkbox',
                'ui_style'    => 'tiles_large',
                'is_required' => true,
                'sort_order'  => 40,
                'depends_on'  => ['group_id' => $typeGroup->id, 'option_ids' => [$typeChannel->id]],
            ]
        );

        foreach (self::COLORS as $idx => $color) {
            $invName = "Обкладинка тверда ({$color})";
            $invItem = InventoryItem::where('name', $invName)->first();

            ServiceParameterOption::updateOrCreate(
                ['group_id' => $coverGroup->id, 'name' => $color],
                [
                    'price_markup'      => 0,
                    'counter_type'      => 'none',
                    'clicks_per_unit'   => 0,
                    'inventory_item_id' => $invItem?->id,
                    'inventory_qty'     => 1,
                    'sort_order'        => ($idx + 1) * 10,
                    'is_active'         => true,
                    'depends_on'        => ['group_id' => $colorGroup->id, 'option_ids' => $channelColorByColor[$color] ?? []],
                ]
            );
        }

        // Auto-flush cached reference data
        app(ReferenceDataService::class)->flush();

        $completeCount = count(self::COMPLETE_SIZES) * count(self::COMPLETE_COLORS);
        $channelCount = count(self::CHANNEL_SIZES) * count(self::COLORS);
        $coverCount = count(self::COLORS);
        $totalInv = $completeCount + $channelCount + $coverCount;

        $this->command->info("✅ Hard binding seeded: 1 service, 4 groups, {$totalInv} inventory items.");
        $this->command->info('   Комплектні: '.count(self::COMPLETE_SIZES).' sizes × '.count(self::COMPLETE_COLORS)." colors = {$completeCount}");
        $this->command->info('   Канали: '.count(self::CHANNEL_SIZES).' sizes × '.count(self::COLORS)." colors = {$channelCount}");
        $this->command->info("   Обкладинки: {$coverCount}");
    }

    // ─── Private: Create inventory items ────────────────

    private function createInventoryItems(int $completeCatId, int $channelCatId): void
    {
        // Комплектні: size × color
        foreach (self::COMPLETE_SIZES as $size) {
            foreach (self::COMPLETE_COLORS as $color) {
                $this->ensureItem($completeCatId, "Палітурка комплектна {$size['mm']}мм ({$color})", $size['cost']);
            }
        }

        // Канали: size × color
        foreach (self::CHANNEL_SIZES as $size) {
            foreach (self::COLORS as $color) {
                $this->ensureItem($channelCatId, "Канал {$size['mm']}мм ({$color})", $size['cost']);
            }
        }

        // Обкладинки: color → канали category
        foreach (self::COVER_COSTS as $color => $cost) {
            $this->ensureItem($channelCatId, "Обкладинка тверда ({$color})", $cost);
        }
    }

    private function ensureItem(int $categoryId, string $name, float $avgCost): void
    {
        // Try to find by name in ANY category (handles migration from old single category)
        $existing = InventoryItem::where('name', $name)->first();

        if ($existing) {
            // Move to correct category if needed (preserves stock!)
            if ($existing->inventory_category_id !== $categoryId) {
                $existing->update(['inventory_category_id' => $categoryId]);
            }

            return;
        }

        InventoryItem::create([
            'inventory_category_id' => $categoryId,
            'name'                  => $name,
            'unit'                  => 'шт',
            'current_quantity'      => 0,
            'avg_cost'              => $avgCost,
            'min_quantity'          => 5,
            'is_active'             => true,
        ]);
    }
}
