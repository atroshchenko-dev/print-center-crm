<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveParameterOptionRequest;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use Illuminate\Http\RedirectResponse;

class ServiceParameterOptionController extends Controller
{
    public function store(SaveParameterOptionRequest $request, ServiceParameterGroup $group): RedirectResponse
    {
        // cost_markup is auto-calculated via ServiceParameterOption::saving() hook
        $group->options()->create($request->validated());
        return back()->with('success', 'Опцію додано.');
    }

    public function update(SaveParameterOptionRequest $request, ServiceParameterOption $option): RedirectResponse
    {
        $option->update($request->validated());
        return back()->with('success', 'Опцію оновлено.');
    }

    public function destroy(ServiceParameterOption $option): RedirectResponse
    {
        $option->delete();
        return back()->with('success', 'Опцію видалено.');
    }
}
