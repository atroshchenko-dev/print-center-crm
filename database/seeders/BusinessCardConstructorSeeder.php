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
 * Business card constructor: Format → Paper → Sidedness → Lamination (optional).
 *
 * Unit: 1 A4 sheet. Operator enters quantity as number of A4 sheets to print.
 *
 * Formats:
 *   - 90×50 мм (standard)
 *   - 85×55 мм (rounded corners / євро)
 *
 * Paper (11 options):
 *   - Standard: 160, 200 г/м² (inventory-linked)
 *   - Coated: 250, 300 г/м² крейдований, gloss and matte apart (inventory-linked)
 *   - Designer: Стардрім опал, Мальмеро сніжок, Білий льон, Жовтий льон (inventory-linked)
 *   - Other: Інший (no inventory, operator enters name in material_description)
 *
 * Sidedness: 4+0 (1 color click), 4+4 (2 color clicks)
 *
 * Lamination (optional): 100 мкм глянець / 100 мкм мат (inventory-linked)
 *
 * Pricing (internal only, at cost):
 *   - price_markup = 0 (no commercial pricing yet)
 *   - cost_markup = AUTO-CALCULATED via model saving hook
 */
class BusinessCardConstructorSeeder extends Seeder
{
    public function run(): void
    {
        $cat = ServiceCategory::where('name', 'Візитівки')->first();
        if (! $cat) {
            $this->command->warn('⚠ Category "Візитівки" not found. Skipping.');

            return;
        }

        $svc = Service::updateOrCreate(
            ['service_category_id' => $cat->id, 'name' => 'Візитівки'],
            [
                'type'                  => 'constructor',
                'base_price_commercial' => 0,
                'base_price_cost'       => 0,
                'counter_type'          => 'color',
                'clicks_per_unit'       => 0,
                'is_active'             => true,
            ]
        );

        // ══════════════════════════════════════════════════
        // Group 1: FORMAT (required, root)
        // ══════════════════════════════════════════════════
        $fmtGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Формат'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_large', 'is_required' => true, 'sort_order' => 10]
        );

        $fmt90 = ServiceParameterOption::updateOrCreate(
            ['group_id' => $fmtGroup->id, 'name' => '90×50 мм'],
            ['price_markup'         => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0,
                'inventory_item_id' => null, 'inventory_qty' => 0, 'sort_order' => 10, 'is_active' => true]
        );
        $fmt85 = ServiceParameterOption::updateOrCreate(
            ['group_id' => $fmtGroup->id, 'name' => '85×55 мм (круглі кути)'],
            ['price_markup'         => 0, 'counter_type' => 'none', 'clicks_per_unit' => 0,
                'inventory_item_id' => null, 'inventory_qty' => 0, 'sort_order' => 20, 'is_active' => true]
        );

        $allFormatIds = [$fmt90->id, $fmt85->id];

        // ══════════════════════════════════════════════════
        // Group 2: PAPER TYPE (required, depends_on format → all formats)
        // ══════════════════════════════════════════════════
        $paperGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Тип паперу'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_small', 'is_required' => true, 'sort_order' => 20]
        );

        // [optionLabel, inventoryName|null, manualCostMarkup (for non-inventory items only)]
        $paperDefs = [
            // Standard office paper (cost_markup auto-calculated from inventory avg_cost)
            ['Папір 160 г/м²',                    'Папір А4 160 г/м²',                               null],
            ['Папір 200 г/м²',                    'Папір А4 200 г/м²',                               null],
            // Coated paper
            ['Крейдований 250 г/м² (глянець)',    'Папір А4 250 г/м² (крейдований, глянець)',        null],
            ['Крейдований 300 г/м² (глянець)',    'Папір А4 300 г/м² (крейдований, глянець)',        null],
            ['Крейдований 250 г/м² (мат)',        'Папір А4 250 г/м² (крейдований, мат)',            null],
            ['Крейдований 300 г/м² (мат)',        'Папір А4 300 г/м² (крейдований, мат)',            null],
            // Designer cardboard A4 (linked to inventory)
            ['Дизайнерський: Стардрім опал',       'Картон А4 дизайнерський (Стардрім опал)',         null],
            ['Дизайнерський: Мальмеро сніжок',     'Картон А4 дизайнерський (Мальмеро сніжок)',       null],
            ['Дизайнерський: Білий льон',          'Картон А4 дизайнерський (Білий льон)',            null],
            ['Дизайнерський: Жовтий льон',         'Картон А4 дизайнерський (Жовтий льон)',           null],
            // Other (no inventory, operator enters name in material_description)
            ['Інший',                              null,                                              0.00],
        ];

        $paperOpts = [];
        foreach ($paperDefs as $idx => [$label, $invName, $manualCost]) {
            $invItem = $invName ? InventoryItem::where('name', $invName)->first() : null;

            $attrs = [
                'price_markup'      => 0,
                'counter_type'      => 'none',
                'clicks_per_unit'   => 0,
                'inventory_item_id' => $invItem?->id,
                'inventory_qty'     => $invItem ? 1 : 0,
                'sort_order'        => ($idx + 1) * 10,
                'is_active'         => true,
                // Paper is available for ALL formats (90×50 and 85×55)
                'depends_on' => ['group_id' => $fmtGroup->id, 'option_ids' => $allFormatIds],
            ];

            if (! $invItem && $manualCost !== null) {
                $attrs['cost_markup'] = $manualCost;
            }

            $paperOpts[$label] = ServiceParameterOption::updateOrCreate(
                ['group_id' => $paperGroup->id, 'name' => $label],
                $attrs
            );
        }

        // ══════════════════════════════════════════════════
        // Group 3: SIDEDNESS (required, depends_on paper)
        // ══════════════════════════════════════════════════
        $sidesGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Сторонність'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_large', 'is_required' => true, 'sort_order' => 30]
        );

        // cost_markup = amortization only (click_cost × clicks_per_unit) — auto-calculated
        $sort = 1;
        foreach ($paperDefs as [$label]) {
            $paperOpt = $paperOpts[$label];

            // 4+0 (single-sided): 1 color click per A4 sheet
            ServiceParameterOption::updateOrCreate(
                ['group_id' => $sidesGroup->id, 'name' => "{$label}: 4+0"],
                [
                    'price_markup'      => 0,
                    'counter_type'      => 'color',
                    'clicks_per_unit'   => 1,
                    'inventory_item_id' => null,
                    'inventory_qty'     => 0,
                    'sort_order'        => $sort++,
                    'is_active'         => true,
                    'depends_on'        => ['group_id' => $paperGroup->id, 'option_ids' => [$paperOpt->id]],
                ]
            );

            // 4+4 (double-sided): 2 color clicks per A4 sheet
            ServiceParameterOption::updateOrCreate(
                ['group_id' => $sidesGroup->id, 'name' => "{$label}: 4+4"],
                [
                    'price_markup'      => 0,
                    'counter_type'      => 'color',
                    'clicks_per_unit'   => 2,
                    'inventory_item_id' => null,
                    'inventory_qty'     => 0,
                    'sort_order'        => $sort++,
                    'is_active'         => true,
                    'depends_on'        => ['group_id' => $paperGroup->id, 'option_ids' => [$paperOpt->id]],
                ]
            );
        }

        // ══════════════════════════════════════════════════
        // Group 4: LAMINATION (optional, always visible)
        // ══════════════════════════════════════════════════
        $lamGroup = ServiceParameterGroup::updateOrCreate(
            ['service_id' => $svc->id, 'name' => 'Ламінація'],
            ['ui_type' => 'radio', 'ui_style' => 'tiles_large', 'is_required' => false, 'sort_order' => 40]
        );

        $lamDefs = [
            // [label, inventoryName] — cost_markup auto-calculated from film avg_cost
            ['100 мкм (глянець)', 'Плівка А4 100 мкм (глянець)'],
            ['100 мкм (мат)',     'Плівка А4 100 мкм (мат)'],
        ];

        foreach ($lamDefs as $idx => [$label, $invName]) {
            $invItem = InventoryItem::where('name', $invName)->first();
            ServiceParameterOption::updateOrCreate(
                ['group_id' => $lamGroup->id, 'name' => $label],
                [
                    'price_markup'      => 0,
                    'counter_type'      => 'none',
                    'clicks_per_unit'   => 0,
                    'inventory_item_id' => $invItem?->id,
                    'inventory_qty'     => 1, // 1 pouch per A4 sheet
                    'sort_order'        => ($idx + 1) * 10,
                    'is_active'         => true,
                    // No depends_on — always visible (optional group)
                ]
            );
        }

        // ── Cleanup old services ─────────────────────────
        Service::where('service_category_id', $cat->id)
            ->where('name', '!=', 'Візитівки')
            ->whereNull('deleted_at')
            ->each(fn ($s) => $s->delete());

        // Auto-flush cached reference data
        app(ReferenceDataService::class)->flush();
        $this->command->info('🔄 Reference data cache flushed.');

        $this->command->info('✅ Business Cards (internal, at cost): 4 groups, '
            .count($paperDefs).' papers, '
            .(count($paperDefs) * 2).' sidedness, '
            .count($lamDefs).' lamination options.');
    }
}
