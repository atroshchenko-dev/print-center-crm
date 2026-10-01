<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEquipmentRequest;
use App\Models\Equipment;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EquipmentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Equipment/Index', [
            'equipment' => Equipment::withTrashed()->latest()->get(),
        ]);
    }

    public function store(StoreEquipmentRequest $request): RedirectResponse
    {
        Equipment::create($request->validated());
        return back()->with('success', 'Апарат додано.');
    }

    public function update(StoreEquipmentRequest $request, Equipment $equipment): RedirectResponse
    {
        $equipment->update($request->validated());
        return back()->with('success', 'Апарат оновлено.');
    }

    public function destroy(Equipment $equipment): RedirectResponse
    {
        $equipment->delete();
        return back()->with('success', 'Апарат деактивовано.');
    }
}
