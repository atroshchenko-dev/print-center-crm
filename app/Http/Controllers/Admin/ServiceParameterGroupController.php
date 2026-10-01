<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveParameterGroupRequest;
use App\Models\Service;
use App\Models\ServiceParameterGroup;
use Illuminate\Http\RedirectResponse;

class ServiceParameterGroupController extends Controller
{
    public function store(SaveParameterGroupRequest $request, Service $service): RedirectResponse
    {
        $service->parameterGroups()->create($request->validated());
        return back()->with('success', 'Групу додано.');
    }

    public function update(SaveParameterGroupRequest $request, ServiceParameterGroup $group): RedirectResponse
    {
        $group->update($request->validated());
        return back()->with('success', 'Групу оновлено.');
    }

    public function destroy(ServiceParameterGroup $group): RedirectResponse
    {
        $group->delete();
        return back()->with('success', 'Групу видалено.');
    }
}
