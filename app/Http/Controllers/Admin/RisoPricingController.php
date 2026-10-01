<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRisoPriceTierRequest;
use App\Http\Requests\Admin\UpdateRisoPriceTiersRequest;
use App\Models\RisoPriceTier;
use App\Services\ReferenceDataService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RisoPricingController extends Controller
{
    public function __construct(
        private readonly ReferenceDataService $refData,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/RisoPricing/Index', [
            'tiers'      => RisoPriceTier::orderBy('min_qty')->get(),
            'paper_cost' => $this->refData->risoPaperCost(),
            // Holes in the ladder, shown on the page that makes them.
            // Since round 30 a run that falls into one is refused rather than
            // priced from the dearest tier, so this is the difference between
            // an admin seeing what they did and an operator meeting a Riso
            // order that will not save.
            'gaps' => RisoPriceTier::gaps(),
        ]);
    }

    public function update(UpdateRisoPriceTiersRequest $request): RedirectResponse
    {
        $data = $request->validated();

        foreach ($data['tiers'] as $tierData) {
            RisoPriceTier::where('id', $tierData['id'])->update([
                'min_qty'       => $tierData['min_qty'],
                'max_qty'       => $tierData['max_qty'],
                'cost_per_copy' => $tierData['cost_per_copy'],
            ]);
        }

        $this->refData->flush('riso_tiers');

        return back()->with('success', 'Тарифи оновлено.');
    }

    public function store(StoreRisoPriceTierRequest $request): RedirectResponse
    {
        RisoPriceTier::create($request->validated());
        $this->refData->flush('riso_tiers');

        return back()->with('success', 'Тариф додано.');
    }

    public function destroy(RisoPriceTier $tier): RedirectResponse
    {
        $tier->delete();
        $this->refData->flush('riso_tiers');

        return back()->with('success', 'Тариф видалено.');
    }
}
