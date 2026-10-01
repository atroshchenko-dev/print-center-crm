<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Services\ReferenceDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function __construct(
        private readonly ReferenceDataService $refData,
    ) {}

    public function index(Request $request): Response
    {
        $query = Service::with(['parameterGroups.options', 'category'])->latest();

        if ($request->boolean('trashed')) {
            $query->withTrashed();
        }

        return Inertia::render('Admin/Services/Index', [
            'services'      => $query->get(),
            'categories'    => \App\Models\ServiceCategory::where('is_active', true)->orderBy('sort_order')->get(),
            'allCategories' => \App\Models\ServiceCategory::withTrashed()->orderBy('sort_order')->get(),
            'showTrashed'   => $request->boolean('trashed'),
        ]);
    }

    // No create(): Admin/Services/Form is an edit-only page — it dereferences
    // `service` during setup, so rendering it with a null service threw a
    // TypeError before the form ever appeared. Creation runs through the modal
    // on the index page, which posts to store() below.

    public function store(StoreServiceRequest $request): RedirectResponse
    {
        Service::create($request->validated());
        $this->refData->flush('services');
        return redirect()->route('admin.services.index')->with('success', 'Послугу додано.');
    }

    public function edit(Service $service): Response
    {
        $service->load('parameterGroups.options.inventoryItem');
        return Inertia::render('Admin/Services/Form', [
            'service'        => $service,
            'categories'     => \App\Models\ServiceCategory::where('is_active', true)->orderBy('sort_order')->get(),
            'inventoryItems' => \App\Models\InventoryItem::where('is_active', true)->orderBy('name')->get(['id','name','avg_cost','unit']),
            'materials'      => \App\Models\Material::where('is_active', true)->orderBy('counter_type')->get(['id','name','counter_type','click_cost']),
        ]);
    }

    public function update(UpdateServiceRequest $request, Service $service): RedirectResponse
    {
        $service->update($request->validated());
        $this->refData->flush('services');
        return redirect()->route('admin.services.index')->with('success', 'Послугу оновлено.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $service->delete(); // Soft delete
        $this->refData->flush('services');
        return back()->with('success', 'Послугу деактивовано.');
    }
}
