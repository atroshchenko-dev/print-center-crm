<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\ServiceParameterOption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * OrderItemBuilder
 *
 * Single source of truth for building order items with price snapshots.
 * Handles all 5 service types: constructor, riso, brochure, diploma, static.
 *
 * Supports two modes:
 *   - Fresh snapshot: builds new JSONB snapshot from constructor options
 *   - Existing snapshot reuse: preserves JSONB from prior order edit (qty-adjusted)
 *
 * Extracted from 4 duplicated call sites:
 *   - OrderCreationService::buildOrderItems()
 *   - OrderController::update()
 *   - BackdatedOrderController::store()
 *   - BackdatedOrderController::update()
 */
class OrderItemBuilder
{
    public function __construct(
        private readonly ConstructorPricingService $constructorPricing,
        private readonly RisoPricingService        $risoPricing,
        private readonly BrochurePricingService    $brochurePricing,
        private readonly DiplomaPricingService     $diplomaPricing,
    ) {}

    /**
     * Pre-load service and option maps to avoid N+1 queries.
     *
     * @param  array<int, array<string, mixed>> $items  Raw item data from request
     * @return array{0: Collection, 1: Collection}      [servicesMap, optionsMap]
     */
    public function preloadMaps(array $items): array
    {
        $serviceIds   = collect($items)->pluck('service_id')->unique()->filter();
        $allOptionIds = collect($items)->flatMap(fn ($i) => $i['selected_option_ids'] ?? [])->unique()->filter();

        $servicesMap = Service::whereIn('id', $serviceIds)->get()->keyBy('id');
        $optionsMap  = ServiceParameterOption::with('group')
            ->whereIn('id', $allOptionIds)->get()->keyBy('id');

        return [$servicesMap, $optionsMap];
    }

    /**
     * Build all items for an order, creating OrderItem records.
     *
     * @param  Order $order                     The order to attach items to
     * @param  array $items                     Raw item data from validated request
     * @param  bool  $supportExistingSnapshot   Whether to support reusing existing snapshots (edit flows)
     * @return array{0: float, 1: float}        [totalCommercial, totalCost]
     */
    public function buildItems(Order $order, array $items, bool $supportExistingSnapshot = false): array
    {
        [$servicesMap, $optionsMap] = $this->preloadMaps($items);

        // A snapshot carries prices and inventory deductions, so one arriving
        // in the request body is only trusted if it is byte-identical to a
        // snapshot already stored for this order. Anything else is rebuilt
        // from authoritative data. (Edit flows soft-delete the old items
        // before rebuilding, hence withTrashed.)
        $trustedSnapshots = $supportExistingSnapshot
            ? OrderItem::withTrashed()
                ->where('order_id', $order->id)
                ->pluck('service_snapshot')
                ->map(fn ($s) => $this->fingerprint(is_array($s) ? $s : []))
                ->flip()
                ->all()
            : [];

        $totalCommercial = 0;
        $totalCost       = 0;

        foreach ($items as $itemData) {
            $service = $servicesMap[$itemData['service_id']]
                ?? throw new \RuntimeException("Service #{$itemData['service_id']} not found.");

            $snapshot = $this->resolveSnapshot($service, $itemData, $optionsMap, $supportExistingSnapshot, $trustedSnapshots);

            OrderItem::create([
                'order_id'               => $order->id,
                'service_id'             => $service->id,
                'service_snapshot'       => $snapshot,
                'service_name'           => $service->name,
                'material_description'   => $itemData['material_description'] ?? null,
                'quantity'               => $itemData['quantity'],
                'unit_price_commercial'  => $snapshot['unit_price_commercial'],
                'unit_price_cost'        => $snapshot['unit_price_cost'],
                'total_price_commercial' => $snapshot['total_price_commercial'],
                'total_price_cost'       => $snapshot['total_price_cost'],
                'bw_clicks'              => $snapshot['hardware_counters']['bw_clicks'] ?? 0,
                'color_clicks'           => $snapshot['hardware_counters']['color_clicks'] ?? 0,
                'riso_clicks'            => $snapshot['hardware_counters']['riso_clicks'] ?? 0,
            ]);

            $totalCommercial += $snapshot['total_price_commercial'];
            $totalCost       += $snapshot['total_price_cost'];
        }

        return [$totalCommercial, $totalCost];
    }

    /**
     * Resolve snapshot for a single item — either reuse existing or build fresh.
     */
    private function resolveSnapshot(Service $service, array $itemData, Collection $optionsMap, bool $supportExisting, array $trustedSnapshots = []): array
    {
        if ($supportExisting) {
            $existingSnapshot = $itemData['existing_snapshot'] ?? null;
            $hasNewOptions    = ! empty($itemData['selected_option_ids'] ?? [])
                || ! empty($itemData['riso_format'])
                || ! empty($itemData['brochure_format'])
                || ! empty($itemData['diploma_params']);

            if ($existingSnapshot && ! $hasNewOptions) {
                // Only reuse a snapshot the order already owns. A forged or
                // edited one does not match and falls through to a rebuild.
                if (isset($trustedSnapshots[$this->fingerprint($existingSnapshot)])) {
                    return $this->reuseSnapshot($existingSnapshot, $itemData['quantity']);
                }

                Log::warning('Rejected untrusted order item snapshot', [
                    'service_id' => $service->id,
                    'quantity'   => $itemData['quantity'] ?? null,
                ]);
            }
        }

        return $this->buildFreshSnapshot($service, $itemData, $optionsMap);
    }

    /**
     * Order-independent fingerprint of a snapshot, used to check that a
     * client-submitted snapshot really came from this order.
     */
    private function fingerprint(array $snapshot): string
    {
        $normalise = function (array $a) use (&$normalise): array {
            ksort($a);

            foreach ($a as $k => $v) {
                if (is_array($v)) {
                    $a[$k] = $normalise($v);
                }
            }

            return $a;
        };

        return hash('sha256', json_encode($normalise($snapshot), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Reuse an existing snapshot, adjusting quantity and prices proportionally.
     */
    private function reuseSnapshot(array $snapshot, int $qty): array
    {
        $oldQty = $snapshot['quantity'] ?? $qty;

        if ($qty !== $oldQty && $oldQty > 0) {
            $unitCost = $snapshot['unit_price_cost'] ?? 0;
            $unitComm = $snapshot['unit_price_commercial'] ?? 0;
            $snapshot['quantity']               = $qty;
            $snapshot['total_price_cost']       = round($unitCost * $qty, 2);
            $snapshot['total_price_commercial'] = round($unitComm * $qty, 2);

            if (isset($snapshot['hardware_counters'])) {
                $ratio = $qty / $oldQty;
                foreach ($snapshot['hardware_counters'] as $key => $val) {
                    $snapshot['hardware_counters'][$key] = (int) round($val * $ratio);
                }
            }
        }

        return $snapshot;
    }

    /**
     * Build a fresh snapshot based on service type.
     */
    private function buildFreshSnapshot(Service $service, array $itemData, Collection $optionsMap): array
    {
        if ($service->isRiso()) {
            return $this->risoPricing->buildSnapshot(
                $service,
                $itemData['quantity'],
                $itemData['riso_format'] ?? 'A3',
                $itemData['riso_sides'] ?? 1,
                $itemData['riso_paper_id'] ?? null,
                (int) ($itemData['riso_originals'] ?? 1),
            );
        }

        if ($service->isBrochure()) {
            return $this->brochurePricing->buildSnapshot(
                $service,
                [
                    'format'         => $itemData['brochure_format'] ?? 'А4',
                    'cover_paper_id' => $itemData['brochure_cover_paper_id'] ?? null,
                    'cover_mode'     => $itemData['brochure_cover_mode'] ?? '1+0',
                    'block_paper_id' => $itemData['brochure_block_paper_id'] ?? null,
                    'block_entries'  => $itemData['brochure_block_entries'] ?? [],
                ],
                $itemData['quantity'],
            );
        }

        if ($service->isDiploma()) {
            return $this->diplomaPricing->buildSnapshot(
                $service,
                $itemData['diploma_params'] ?? [],
                $itemData['quantity'],
            );
        }

        // Constructor (default — includes static services)
        $selectedOptionIds = $itemData['selected_option_ids'] ?? [];
        $selectedOptions   = collect($selectedOptionIds)
            ->map(fn ($id) => $optionsMap[$id]
                ?? throw new \RuntimeException("Option #{$id} not found."));

        $customerPaper = (bool) ($itemData['customer_paper'] ?? false);

        return $this->constructorPricing->buildSnapshot(
            $service,
            $selectedOptions,
            $itemData['quantity'],
            $customerPaper,
        );
    }
}
