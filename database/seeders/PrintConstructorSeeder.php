<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Services\ReferenceDataService;
use Illuminate\Database\Seeder;

/**
 * Constructor services with cascading groups.
 *
 * B/W:         Format → Paper → Sidedness → Fill
 * Color:       Format → Paper → Sidedness → Fill
 * Scanning:    Format (А4/А3)
 * Lamination:  Format → Film Type
 */
class PrintConstructorSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedBw();
        $this->seedColor();
        $this->seedScanning();
        $this->seedLamination();

        // Auto-flush cached reference data to prevent stale __PHP_Incomplete_Class errors
        app(ReferenceDataService::class)->flush();
        $this->command->info('🔄 Reference data cache flushed.');
    }

    // ═══════════════════════════════════════════════════
    //  Ч/Б ДРУК
    // ═══════════════════════════════════════════════════
    private function seedBw(): void
    {
        $cat = ServiceCategory::where('name', 'Чорно-білий друк')->first();
        if (! $cat) {
            return;
        }

        $svc = Service::updateOrCreate(
            ['service_category_id' => $cat->id, 'name' => 'Чорно-білий друк'],
            [
                'type'                  => 'constructor',
                'base_price_commercial' => 0,
                'base_price_cost'       => 0,
                'counter_type'          => 'bw',
                'clicks_per_unit'       => 0,
                'is_active'             => true,
            ]
        );

        // ── Group 1: Format (required) ──
        $fmtGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Формат'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_large', 'is_required' => true, 'sort_order' => 10]
        );
        $fmtA4 = ServiceParameterOption::updateOrCreate(
            ['group_id' => $fmtGroup->id, 'name' => 'А4'],
            ['price_markup'         => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0,
                'inventory_item_id' => null, 'inventory_qty' => 0, 'sort_order' => 10, 'is_active' => true]
        );
        $fmtA3 = ServiceParameterOption::updateOrCreate(
            ['group_id' => $fmtGroup->id, 'name' => 'А3'],
            ['price_markup'         => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0,
                'inventory_item_id' => null, 'inventory_qty' => 0, 'sort_order' => 20, 'is_active' => true]
        );

        // ── Group 2: Paper (required, option depends_on format) ──
        $paperGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Тип паперу'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_small', 'is_required' => true, 'sort_order' => 20]
        );

        $paperDefs = [
            'А4' => [
                ['Папір 80 г/м²',               'Папір А4 80 г/м²'],
                ['Папір 160 г/м²',              'Папір А4 160 г/м²'],
                ['Папір 200 г/м²',              'Папір А4 200 г/м²'],
                ['Папір 160 г/м² (паст. рожевий)',   'Папір А4 160 г/м² (пастельний рожевий)'],
                ['Папір 160 г/м² (паст. жовтий)',    'Папір А4 160 г/м² (пастельний жовтий)'],
                ['Папір 160 г/м² (паст. зелений)',   'Папір А4 160 г/м² (пастельний зелений)'],
                ['Папір 160 г/м² (паст. блакитний)', 'Папір А4 160 г/м² (пастельний блакитний)'],
                ['Папір 150 г/м² (крейдований, глянець)', 'Папір А4 150 г/м² (крейдований, глянець)'],
                ['Папір 250 г/м² (крейдований, глянець)', 'Папір А4 250 г/м² (крейдований, глянець)'],
                ['Папір 300 г/м² (крейдований, глянець)', 'Папір А4 300 г/м² (крейдований, глянець)'],
                ['Папір 150 г/м² (крейдований, мат)', 'Папір А4 150 г/м² (крейдований, мат)'],
                ['Папір 250 г/м² (крейдований, мат)', 'Папір А4 250 г/м² (крейдований, мат)'],
                ['Папір 300 г/м² (крейдований, мат)', 'Папір А4 300 г/м² (крейдований, мат)'],
            ],
            'А3' => [
                ['Папір 80 г/м²',               'Папір А3 80 г/м²'],
                ['Папір 160 г/м²',              'Папір А3 160 г/м²'],
                ['Папір 200 г/м²',              'Папір А3 200 г/м²'],
                ['Папір 160 г/м² (паст. рожевий)',   'Папір А3 160 г/м² (пастельний рожевий)'],
                ['Папір 160 г/м² (паст. жовтий)',    'Папір А3 160 г/м² (пастельний жовтий)'],
                ['Папір 160 г/м² (паст. зелений)',   'Папір А3 160 г/м² (пастельний зелений)'],
                ['Папір 160 г/м² (паст. блакитний)', 'Папір А3 160 г/м² (пастельний блакитний)'],
                ['Папір 150 г/м² (крейдований, глянець)', 'Папір А3 150 г/м² (крейдований, глянець)'],
                ['Папір 250 г/м² (крейдований, глянець)', 'Папір А3 250 г/м² (крейдований, глянець)'],
                ['Папір 300 г/м² (крейдований, глянець)', 'Папір А3 300 г/м² (крейдований, глянець)'],
                ['Папір 150 г/м² (крейдований, мат)', 'Папір А3 150 г/м² (крейдований, мат)'],
                ['Папір 250 г/м² (крейдований, мат)', 'Папір А3 250 г/м² (крейдований, мат)'],
                ['Папір 300 г/м² (крейдований, мат)', 'Папір А3 300 г/м² (крейдований, мат)'],
            ],
        ];

        $fmtOptMap = ['А4' => $fmtA4, 'А3' => $fmtA3];
        $sort = 10;
        foreach ($paperDefs as $fmt => $papers) {
            foreach ($papers as [$label, $invName]) {
                $invItem = InventoryItem::where('name', $invName)->first();
                ServiceParameterOption::updateOrCreate(
                    ['group_id' => $paperGroup->id, 'name' => "{$fmt}: {$label}"],
                    [
                        'price_markup'      => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0,
                        'inventory_item_id' => $invItem?->id, 'inventory_qty' => 1,
                        'sort_order'        => $sort++, 'is_active' => true,
                        'depends_on'        => ['group_id' => $fmtGroup->id, 'option_ids' => [$fmtOptMap[$fmt]->id]],
                    ]
                );
            }
        }

        // ── Group 3: Sidedness (required, option depends_on format) ──
        $sidesGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Сторонність'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_large', 'is_required' => true, 'sort_order' => 30]
        );

        // [name, markup, clicks, depends_on_format]
        $sidesDefs = [
            ['А4: 1+0', 3.00,  1, $fmtA4->id],
            ['А4: 1+1', 6.00,  2, $fmtA4->id],
            ['А3: 1+0', 8.00,  2, $fmtA3->id],
            ['А3: 1+1', 16.00, 4, $fmtA3->id],
        ];

        $sidesOpts = [];
        foreach ($sidesDefs as $idx => [$name, $markup, $clicks, $fmtOptId]) {
            $sidesOpts[$name] = ServiceParameterOption::updateOrCreate(
                ['group_id' => $sidesGroup->id, 'name' => $name],
                [
                    'price_markup'      => $markup, 'counter_type' => 'bw', 'clicks_per_unit' => $clicks,
                    'inventory_item_id' => null, 'inventory_qty' => 0,
                    'sort_order'        => ($idx + 1) * 10, 'is_active' => true,
                    'depends_on'        => ['group_id' => $fmtGroup->id, 'option_ids' => [$fmtOptId]],
                ]
            );
        }

        // ── Group 4: Fill (optional, option depends_on sidedness) ──
        $fillGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Заповненість'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_small', 'is_required' => false, 'sort_order' => 40]
        );

        // Fill extras: [sides_opt_name, fill_label, extra_markup]
        $fillDefs = [
            ['А4: 1+0', 'до 50%',   0],
            ['А4: 1+0', '51-100%',   3.00],
            ['А4: 1+1', 'до 50%',   0],
            ['А4: 1+1', '51-100%',   6.00],
            ['А3: 1+0', 'до 50%',   0],
            ['А3: 1+0', '51-100%',   6.00],
            ['А3: 1+1', 'до 50%',   0],
            ['А3: 1+1', '51-100%',   12.00],
        ];

        foreach ($fillDefs as $idx => [$sidesName, $fillLabel, $extra]) {
            ServiceParameterOption::updateOrCreate(
                ['group_id' => $fillGroup->id, 'name' => "{$sidesName}: {$fillLabel}"],
                [
                    'price_markup'      => $extra, 'counter_type' => 'none', 'clicks_per_unit' => 0,
                    'inventory_item_id' => null, 'inventory_qty' => 0,
                    'sort_order'        => $idx + 1, 'is_active' => true,
                    'depends_on'        => ['group_id' => $sidesGroup->id, 'option_ids' => [$sidesOpts[$sidesName]->id]],
                ]
            );
        }

        // Cleanup old services
        Service::where('service_category_id', $cat->id)
            ->where('name', '!=', 'Чорно-білий друк')
            ->whereNull('deleted_at')
            ->each(fn ($s) => $s->delete());

        $this->command->info('✅ B/W: 1 service, cascade Format → Paper → Sidedness → Fill.');
    }

    // ═══════════════════════════════════════════════════
    //  КОЛЬОРОВИЙ ДРУК
    // ═══════════════════════════════════════════════════
    private function seedColor(): void
    {
        $cat = ServiceCategory::where('name', 'Кольоровий друк')->first();
        if (! $cat) {
            return;
        }

        $svc = Service::updateOrCreate(
            ['service_category_id' => $cat->id, 'name' => 'Кольоровий друк'],
            [
                'type'                  => 'constructor',
                'base_price_commercial' => 0,
                'base_price_cost'       => 0,
                'counter_type'          => 'color',
                'clicks_per_unit'       => 0,
                'is_active'             => true,
            ]
        );

        // ── Group 1: Format ──
        $fmtGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Формат'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_large', 'is_required' => true, 'sort_order' => 10]
        );
        $fmtA4 = ServiceParameterOption::updateOrCreate(
            ['group_id' => $fmtGroup->id, 'name' => 'А4'],
            ['price_markup'         => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0,
                'inventory_item_id' => null, 'inventory_qty' => 0, 'sort_order' => 10, 'is_active' => true]
        );
        $fmtA3 = ServiceParameterOption::updateOrCreate(
            ['group_id' => $fmtGroup->id, 'name' => 'А3'],
            ['price_markup'         => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0,
                'inventory_item_id' => null, 'inventory_qty' => 0, 'sort_order' => 20, 'is_active' => true]
        );

        // ── Group 2: Paper (depends_on format, carries base price) ──
        $paperGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Тип паперу'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_small', 'is_required' => true, 'sort_order' => 20]
        );

        // Paper → base markup (single side) per format
        // tier_key groups papers for fill cascading
        $paperDefs = [
            'А4' => [
                ['Папір 80 г/м²',               'Папір А4 80 г/м²',               9.00,  'std'],
                ['Папір 160 г/м²',              'Папір А4 160 г/м²',              14.00, 'dense'],
                ['Папір 200 г/м²',              'Папір А4 200 г/м²',              14.00, 'dense'],
                ['Папір 160 г/м² (паст. рожевий)',   'Папір А4 160 г/м² (пастельний рожевий)',   14.00, 'dense'],
                ['Папір 160 г/м² (паст. жовтий)',    'Папір А4 160 г/м² (пастельний жовтий)',    14.00, 'dense'],
                ['Папір 160 г/м² (паст. зелений)',   'Папір А4 160 г/м² (пастельний зелений)',   14.00, 'dense'],
                ['Папір 160 г/м² (паст. блакитний)', 'Папір А4 160 г/м² (пастельний блакитний)', 14.00, 'dense'],
                ['Папір 150 г/м² (крейдований, глянець)', 'Папір А4 150 г/м² (крейдований, глянець)', 14.00, 'dense'],
                ['Папір 250 г/м² (крейдований, глянець)', 'Папір А4 250 г/м² (крейдований, глянець)', 18.00, 'coated'],
                ['Папір 300 г/м² (крейдований, глянець)', 'Папір А4 300 г/м² (крейдований, глянець)', 18.00, 'coated'],
                // Matte costs the same as gloss — the price list groups both as «Щільний матов./глянц.»
                ['Папір 150 г/м² (крейдований, мат)', 'Папір А4 150 г/м² (крейдований, мат)', 14.00, 'dense'],
                ['Папір 250 г/м² (крейдований, мат)', 'Папір А4 250 г/м² (крейдований, мат)', 18.00, 'coated'],
                ['Папір 300 г/м² (крейдований, мат)', 'Папір А4 300 г/м² (крейдований, мат)', 18.00, 'coated'],
            ],
            'А3' => [
                ['Папір 80 г/м²',               'Папір А3 80 г/м²',               14.00, 'std'],
                ['Папір 160 г/м²',              'Папір А3 160 г/м²',              15.00, 'dense'],
                ['Папір 200 г/м²',              'Папір А3 200 г/м²',              15.00, 'dense'],
                ['Папір 160 г/м² (паст. рожевий)',   'Папір А3 160 г/м² (пастельний рожевий)',   15.00, 'dense'],
                ['Папір 160 г/м² (паст. жовтий)',    'Папір А3 160 г/м² (пастельний жовтий)',    15.00, 'dense'],
                ['Папір 160 г/м² (паст. зелений)',   'Папір А3 160 г/м² (пастельний зелений)',   15.00, 'dense'],
                ['Папір 160 г/м² (паст. блакитний)', 'Папір А3 160 г/м² (пастельний блакитний)', 15.00, 'dense'],
                ['Папір 150 г/м² (крейдований, глянець)', 'Папір А3 150 г/м² (крейдований, глянець)', 15.00, 'dense'],
                ['Папір 250 г/м² (крейдований, глянець)', 'Папір А3 250 г/м² (крейдований, глянець)', 26.00, 'coated'],
                ['Папір 300 г/м² (крейдований, глянець)', 'Папір А3 300 г/м² (крейдований, глянець)', 26.00, 'coated'],
                ['Папір 150 г/м² (крейдований, мат)', 'Папір А3 150 г/м² (крейдований, мат)', 15.00, 'dense'],
                ['Папір 250 г/м² (крейдований, мат)', 'Папір А3 250 г/м² (крейдований, мат)', 26.00, 'coated'],
                ['Папір 300 г/м² (крейдований, мат)', 'Папір А3 300 г/м² (крейдований, мат)', 26.00, 'coated'],
            ],
        ];

        $fmtOptMap = ['А4' => $fmtA4, 'А3' => $fmtA3];
        $paperOpts = []; // key: "А4: Папір 80 г/м²" => option model
        $sort = 10;

        foreach ($paperDefs as $fmt => $papers) {
            foreach ($papers as [$label, $invName, $markup, $tier]) {
                $invItem = InventoryItem::where('name', $invName)->first();
                $key = "{$fmt}: {$label}";
                $paperOpts[$key] = ServiceParameterOption::updateOrCreate(
                    ['group_id' => $paperGroup->id, 'name' => $key],
                    [
                        'price_markup'      => $markup, 'counter_type' => 'none', 'clicks_per_unit' => 0,
                        'inventory_item_id' => $invItem?->id, 'inventory_qty' => 1,
                        'sort_order'        => $sort++, 'is_active' => true,
                        'depends_on'        => ['group_id' => $fmtGroup->id, 'option_ids' => [$fmtOptMap[$fmt]->id]],
                    ]
                );
            }
        }

        // ── Group 3: Sidedness (depends_on paper — carries extra for duplex) ──
        $sidesGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Сторонність'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_large', 'is_required' => true, 'sort_order' => 30]
        );

        $sidesOpts = []; // key: "А4: Папір 80 г/м²: 4+0" => option model
        $sort = 1;

        foreach ($paperDefs as $fmt => $papers) {
            foreach ($papers as [$label, $invName, $markup, $tier]) {
                $paperKey = "{$fmt}: {$label}";
                $paperOptId = $paperOpts[$paperKey]->id;

                // 4+0: markup 0 (single side already in paper), clicks based on format
                $clicksSingle = $fmt === 'А4' ? 1 : 2;
                $sidesOpts["{$paperKey}: 4+0"] = ServiceParameterOption::updateOrCreate(
                    ['group_id' => $sidesGroup->id, 'name' => "{$paperKey}: 4+0"],
                    [
                        'price_markup'      => 0, 'counter_type' => 'color', 'clicks_per_unit' => $clicksSingle,
                        'inventory_item_id' => null, 'inventory_qty' => 0,
                        'sort_order'        => $sort++, 'is_active' => true,
                        'depends_on'        => ['group_id' => $paperGroup->id, 'option_ids' => [$paperOptId]],
                    ]
                );

                // 4+4: markup = paper markup again (doubles price), clicks doubled
                $sidesOpts["{$paperKey}: 4+4"] = ServiceParameterOption::updateOrCreate(
                    ['group_id' => $sidesGroup->id, 'name' => "{$paperKey}: 4+4"],
                    [
                        'price_markup'      => $markup, 'counter_type' => 'color', 'clicks_per_unit' => $clicksSingle * 2,
                        'inventory_item_id' => null, 'inventory_qty' => 0,
                        'sort_order'        => $sort++, 'is_active' => true,
                        'depends_on'        => ['group_id' => $paperGroup->id, 'option_ids' => [$paperOptId]],
                    ]
                );
            }
        }

        // ── Group 4: Fill (optional, depends_on sidedness) ──
        $fillGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Заповненість'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_small', 'is_required' => false, 'sort_order' => 40]
        );

        // Fill extras per tier (single-side extras, will be ×2 for 4+4)
        $fillTiers = [
            'А4' => [
                'std'    => [['до 50%', 0], ['51-100%', 6.00]],
                'dense'  => [['до 25%', 0], ['26-75%', 3.00], ['76-100%', 6.00]],
                'coated' => [['до 25%', 0], ['26-75%', 3.00], ['76-100%', 6.00]],
            ],
            'А3' => [
                'std'    => [['до 50%', 0], ['51-100%', 10.00]],
                'dense'  => [['до 25%', 0], ['26-75%', 6.00], ['76-100%', 17.00]],
                'coated' => [['до 25%', 0], ['26-75%', 4.00], ['76-100%', 10.00]],
            ],
        ];

        $sort = 1;
        foreach ($paperDefs as $fmt => $papers) {
            foreach ($papers as [$label, $invName, $markup, $tier]) {
                $paperKey = "{$fmt}: {$label}";
                $fills = $fillTiers[$fmt][$tier];

                foreach (['4+0', '4+4'] as $sides) {
                    $sidesKey = "{$paperKey}: {$sides}";
                    $sidesOptId = $sidesOpts[$sidesKey]->id;
                    $mult = $sides === '4+4' ? 2 : 1;

                    foreach ($fills as [$fillLabel, $fillExtraSingle]) {
                        ServiceParameterOption::updateOrCreate(
                            ['group_id' => $fillGroup->id, 'name' => "{$sidesKey}: {$fillLabel}"],
                            [
                                'price_markup'      => $fillExtraSingle * $mult,
                                'counter_type'      => 'none', 'clicks_per_unit' => 0,
                                'inventory_item_id' => null, 'inventory_qty' => 0,
                                'sort_order'        => $sort++, 'is_active' => true,
                                'depends_on'        => ['group_id' => $sidesGroup->id, 'option_ids' => [$sidesOptId]],
                            ]
                        );
                    }
                }
            }
        }

        // Cleanup old services
        Service::where('service_category_id', $cat->id)
            ->where('name', '!=', 'Кольоровий друк')
            ->whereNull('deleted_at')
            ->each(fn ($s) => $s->delete());

        $this->command->info('✅ Color: 1 service, cascade Format → Paper → Sidedness → Fill.');
    }

    // ═════════════════════════════════════════════════════════
    // SCANNING
    // ═════════════════════════════════════════════════════════

    private function seedScanning(): void
    {
        $cat = ServiceCategory::where('name', 'Сканування')->first();
        if (! $cat) {
            return;
        }

        $svc = Service::updateOrCreate(
            ['service_category_id' => $cat->id, 'name' => 'Сканування'],
            [
                'type'                  => 'constructor',
                'base_price_commercial' => 0,
                'base_price_cost'       => 0,
                'counter_type'          => 'none',
                'clicks_per_unit'       => 0,
                'is_active'             => true,
            ]
        );

        // ── Group 1: Format ──
        $fmtGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Формат'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_large', 'is_required' => true, 'sort_order' => 10]
        );

        ServiceParameterOption::updateOrCreate(
            ['group_id' => $fmtGroup->id, 'name' => 'А4'],
            ['price_markup'         => 6.00, 'cost_markup' => 0.50,
                'counter_type'      => 'none', 'clicks_per_unit' => 0,
                'inventory_item_id' => null, 'inventory_qty' => 0, 'sort_order' => 10, 'is_active' => true]
        );
        ServiceParameterOption::updateOrCreate(
            ['group_id' => $fmtGroup->id, 'name' => 'А3'],
            ['price_markup'         => 10.00, 'cost_markup' => 1.00,
                'counter_type'      => 'none', 'clicks_per_unit' => 0,
                'inventory_item_id' => null, 'inventory_qty' => 0, 'sort_order' => 20, 'is_active' => true]
        );

        // Cleanup old static services in this category
        Service::where('service_category_id', $cat->id)
            ->where('name', '!=', 'Сканування')
            ->whereNull('deleted_at')
            ->each(fn ($s) => $s->delete());

        $this->command->info('✅ Scanning: 1 service, cascade Format.');
    }

    // ═════════════════════════════════════════════════════════
    // LAMINATION
    // ═════════════════════════════════════════════════════════

    private function seedLamination(): void
    {
        $cat = ServiceCategory::where('name', 'Ламінування')->first();
        if (! $cat) {
            return;
        }

        $svc = Service::updateOrCreate(
            ['service_category_id' => $cat->id, 'name' => 'Ламінування'],
            [
                'type'                  => 'constructor',
                'base_price_commercial' => 0,
                'base_price_cost'       => 0,
                'counter_type'          => 'none',
                'clicks_per_unit'       => 0,
                'is_active'             => true,
            ]
        );

        // ── Group 1: Format ──
        $fmtGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Формат'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_large', 'is_required' => true, 'sort_order' => 10]
        );
        $fmtA4 = ServiceParameterOption::updateOrCreate(
            ['group_id' => $fmtGroup->id, 'name' => 'А4'],
            ['price_markup'         => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0,
                'inventory_item_id' => null, 'inventory_qty' => 0, 'sort_order' => 10, 'is_active' => true]
        );
        $fmtA3 = ServiceParameterOption::updateOrCreate(
            ['group_id' => $fmtGroup->id, 'name' => 'А3'],
            ['price_markup'         => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0,
                'inventory_item_id' => null, 'inventory_qty' => 0, 'sort_order' => 20, 'is_active' => true]
        );

        // ── Group 2: Film Type (depends_on format) ──
        $filmGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Тип плівки'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_small', 'is_required' => true, 'sort_order' => 20]
        );

        // Film options per format — [label, commercial_price, inventory_name]
        $filmDefs = [
            'А4' => [
                ['100 мкм (глянець)', 27.00, 'Плівка А4 100 мкм (глянець)'],
                ['100 мкм (мат)',     30.00, 'Плівка А4 100 мкм (мат)'],
                ['200 мкм (глянець)', 32.00, 'Плівка А4 200 мкм (глянець)'],
            ],
            'А3' => [
                ['100 мкм (глянець)', 35.00, 'Плівка А3 100 мкм (глянець)'],
                ['100 мкм (мат)',     40.00, 'Плівка А3 100 мкм (мат)'],
                ['200 мкм (глянець)', 45.00, 'Плівка А3 200 мкм (глянець)'],
            ],
        ];

        $fmtMap = ['А4' => $fmtA4, 'А3' => $fmtA3];
        $sort = 1;

        foreach ($filmDefs as $fmt => $films) {
            $fmtOpt = $fmtMap[$fmt];
            foreach ($films as [$label, $price, $invName]) {
                $invItem = InventoryItem::where('name', $invName)->first();
                $optName = "{$fmt}: {$label}";
                ServiceParameterOption::updateOrCreate(
                    ['group_id' => $filmGroup->id, 'name' => $optName],
                    [
                        'price_markup'      => $price,
                        'counter_type'      => 'none',
                        'clicks_per_unit'   => 0,
                        'inventory_item_id' => $invItem?->id,
                        'inventory_qty'     => 1,
                        'sort_order'        => $sort++,
                        'is_active'         => true,
                        'depends_on'        => [
                            'group_id'   => $fmtGroup->id,
                            'option_ids' => [$fmtOpt->id],
                        ],
                    ]
                );
            }
        }

        // Cleanup old static services in this category
        Service::where('service_category_id', $cat->id)
            ->where('name', '!=', 'Ламінування')
            ->whereNull('deleted_at')
            ->each(fn ($s) => $s->delete());

        $this->command->info('✅ Lamination: 1 service, cascade Format → Film Type.');
    }
}
