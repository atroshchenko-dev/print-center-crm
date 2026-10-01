<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Constructor\CalculatePriceRequest;
use App\Models\Service;
use App\Models\ServiceParameterOption;
use App\Services\ConstructorPricingService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ConstructorController
 *
 * Serves the Constructor UI template and live price calculations.
 * The calculate() endpoint is called on option change (live update).
 */
class ConstructorController extends Controller
{
    public function __construct(
        private readonly ConstructorPricingService $pricingService,
    ) {}

    /**
     * Get full template data for a constructor service (parameter groups + options).
     */
    public function template(Service $service): Response
    {
        abort_unless($service->isConstructor(), 404, 'Not a constructor service.');

        $service->load('parameterGroups.options');

        return Inertia::render('Constructor/Panel', [
            'service' => $service,
        ]);
    }

    /**
     * Live price calculation endpoint.
     * Called via Inertia partial reload or XHR when options change.
     *
     * Returns: { unit_price_commercial, unit_price_cost, total_price_commercial, ... }
     */
    public function calculate(CalculatePriceRequest $request): JsonResponse
    {
        $data    = $request->validated();
        $service = Service::findOrFail($data['service_id']);

        abort_unless($service->isConstructor(), 422, 'Only constructor services support live calculation.');

        $options = ServiceParameterOption::with('group')
            ->whereIn('id', $data['selected_option_ids'] ?? [])
            ->where('is_active', true)
            ->get();

        $result = $this->pricingService->calculate($service, $options, $data['quantity'], (bool) ($data['customer_paper'] ?? false));

        return response()->json($result);
    }
}
