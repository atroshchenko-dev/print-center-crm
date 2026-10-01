<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CounterType;
use App\Models\Service;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ConstructorPricingService
 *
 * Calculates real-time pricing for constructor-type services.
 * Used both by the backend (on order save) and can feed the frontend
 * via an Inertia partial / API endpoint.
 *
 * Formula per ТЗ Додаток А.3:
 *   unit_price_commercial = base_price_commercial + Σ(option.price_markup)
 *   unit_price_cost       = base_price_cost       + Σ(option.cost_markup)
 *   clicks per type       = Σ(option.clicks_per_unit) grouped by counter_type
 *
 * Static services have no options, so their counter mapping lives on the service
 * row (ТЗ §3.3) and is added on top of the option sum — for static services only,
 * or a constructor would count every click twice.
 */
class ConstructorPricingService
{
    /**
     * Calculate pricing for a constructor service with selected options.
     *
     * @param  Service                                 $service        The constructor service
     * @param  Collection<int,ServiceParameterOption>  $selectedOptions Options chosen by the executor
     * @param  int                                     $quantity        Print run (тираж)
     * @return array{
     *   unit_price_commercial: float,
     *   unit_price_cost: float,
     *   total_price_commercial: float,
     *   total_price_cost: float,
     *   bw_clicks: int,
     *   color_clicks: int,
     *   riso_clicks: int,
     *   constructor_snapshot: array,
     * }
     */
    public function calculate(
        Service $service,
        Collection $selectedOptions,
        int $quantity,
        bool $customerPaper = false,
    ): array {
        // Paper group name used across print constructors (ЧБ + Кольоровий друк)
        $paperGroupName = ServiceParameterGroup::PAPER;
        $unitCommercial = (float) $service->base_price_commercial;
        $unitCost       = (float) $service->base_price_cost;
        $bwClicks       = 0;
        $colorClicks    = 0;
        $risoClicks  = 0;

        $constructorSnapshot = [];
        $paperZeroed         = false;

        foreach ($selectedOptions as $option) {
            // Customer paper: zero out paper-group option costs & inventory
            $isPaperOption = $customerPaper && ($option->group->name ?? '') === $paperGroupName;
            $paperZeroed   = $paperZeroed || $isPaperOption;

            $unitCommercial += $isPaperOption ? 0 : (float) $option->price_markup;
            $unitCost       += $isPaperOption ? 0 : (float) $option->cost_markup;

            // Counter accumulation (null-safe: counter_type may be null in DB)
            $counterValue = $option->counter_type?->value ?? 'none';
            match ($counterValue) {
                'bw'      => $bwClicks      += $option->clicks_per_unit ?? 0,
                'color'   => $colorClicks   += $option->clicks_per_unit ?? 0,
                'riso'    => $risoClicks    += $option->clicks_per_unit ?? 0,
                default   => null,
            };

            // Build snapshot entry (see ТЗ Додаток А.5)
            $constructorSnapshot[] = [
                'option_id'         => $option->id,
                'group_name'        => $option->group->name,
                'option_name'       => $option->name,
                'price_markup'      => $isPaperOption ? 0 : (float) $option->price_markup,
                'cost_markup'       => $isPaperOption ? 0 : (float) $option->cost_markup,
                'counter_type'      => $option->counter_type?->value,
                'clicks'            => $option->clicks_per_unit,
                'inventory_item_id' => $isPaperOption ? null : $option->inventory_item_id,
                'inventory_qty'     => $isPaperOption ? 0 : (float) ($option->inventory_qty ?? 0),
            ];
        }

        // A static service carries its counter mapping on the service row itself
        // (ТЗ §3.3) — it has no options to carry it. Constructors map counters per
        // option, so this must never apply to them, or every click would be counted
        // twice. The admin form has always offered both fields for static services
        // and nothing read them: a service set to bw/5 recorded zero clicks, and its
        // real runs fell silently into "unaccounted" on the counter report.
        if ($service->isStatic()) {
            $staticClicks = (int) $service->clicks_per_unit;

            // Matched on the enum rather than its ->value: the column is
            // nullable in the database while the model docblock types it as a
            // plain CounterType, so a `?? 'none'` fallback reads as dead code to
            // static analysis. A null simply falls through to default here.
            match ($service->counter_type) {
                CounterType::Bw    => $bwClicks    += $staticClicks,
                CounterType::Color => $colorClicks += $staticClicks,
                CounterType::Riso  => $risoClicks  += $staticClicks,
                default            => null,
            };
        }

        // The rule is keyed on a group name, so a rename in the admin UI would
        // make it a silent no-op and the customer would pay for paper they
        // brought themselves. Nothing to abort over — the order is still
        // priceable — but it must not pass unnoticed (audit finding L-6).
        if ($customerPaper && ! $paperZeroed) {
            Log::warning('Customer paper requested but no paper option was zeroed', [
                'service_id'       => $service->id,
                'service_name'     => $service->name,
                'expected_group'   => $paperGroupName,
                'selected_groups'  => $selectedOptions->map(fn ($o) => $o->group->name ?? null)->unique()->values()->all(),
            ]);
        }

        return [
            'unit_price_commercial'  => round($unitCommercial, 2),
            'unit_price_cost'        => round($unitCost, 2),
            'total_price_commercial' => round($unitCommercial * $quantity, 2),
            'total_price_cost'       => round($unitCost * $quantity, 2),
            'bw_clicks'              => $bwClicks * $quantity,
            'color_clicks'           => $colorClicks * $quantity,
            'riso_clicks'         => $risoClicks * $quantity,
            'constructor_snapshot'   => $constructorSnapshot,
        ];
    }

    /**
     * Build the full JSONB service_snapshot for order_items table.
     * Includes all data required for immutable historical record.
     */
    public function buildSnapshot(
        Service $service,
        Collection $selectedOptions,
        int $quantity,
        bool $customerPaper = false,
    ): array {
        $pricing = $this->calculate($service, $selectedOptions, $quantity, $customerPaper);

        $snapshot = [
            'service_id'              => $service->id,
            'service_name'            => $service->name,
            'service_type'            => $service->type->value,
            'quantity'                => $quantity,
            'unit_price_commercial'   => $pricing['unit_price_commercial'],
            'unit_price_cost'         => $pricing['unit_price_cost'],
            'total_price_commercial'  => $pricing['total_price_commercial'],
            'total_price_cost'        => $pricing['total_price_cost'],
            'hardware_counters'       => [
                'bw_clicks'      => $pricing['bw_clicks'],
                'color_clicks'   => $pricing['color_clicks'],
                'riso_clicks' => $pricing['riso_clicks'],
            ],
            'constructor_snapshot'    => $pricing['constructor_snapshot'],
        ];

        if ($customerPaper) {
            $snapshot['customer_paper'] = true;
        }

        return $snapshot;
    }
}
