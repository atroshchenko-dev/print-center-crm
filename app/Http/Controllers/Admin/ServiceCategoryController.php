<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderRequest;
use App\Http\Requests\Admin\SaveServiceCategoryRequest;
use App\Models\ServiceCategory;
use App\Services\ReferenceDataService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServiceCategoryController extends Controller
{
    public function __construct(
        private readonly ReferenceDataService $refData,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/ServiceCategories/Index', [
            'categories' => ServiceCategory::withTrashed()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(SaveServiceCategoryRequest $request): RedirectResponse
    {
        ServiceCategory::create($request->validated());
        $this->refData->flush('categories');
        return back()->with('success', 'Категорію послуг додано.');
    }

    public function update(SaveServiceCategoryRequest $request, ServiceCategory $category): RedirectResponse
    {
        $category->update($request->validated());
        $this->refData->flush('categories');
        return back()->with('success', 'Категорію оновлено.');
    }

    public function destroy(ServiceCategory $category): RedirectResponse
    {
        $category->delete();
        $this->refData->flush('categories');
        return back()->with('success', 'Категорію успішно переміщено в архів (деактивовано).');
    }

    public function reorder(ReorderRequest $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validated();

        \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            foreach ($data['order'] as $item) {
                ServiceCategory::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
            }
        });

        $this->refData->flush('categories');

        return response()->json(['ok' => true]);
    }
}
