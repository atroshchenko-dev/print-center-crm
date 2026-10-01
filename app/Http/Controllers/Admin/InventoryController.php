<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustEmptyTonerRequest;
use App\Http\Requests\Admin\ReorderInventoryRequest;
use App\Http\Requests\Admin\StoreInventoryConversionRequest;
use App\Http\Requests\Admin\StoreInventoryItemRequest;
use App\Http\Requests\Admin\StoreInventoryReceiptRequest;
use App\Http\Requests\Admin\StoreTonerInstallRequest;
use App\Http\Requests\Admin\StoreTonerRefillRequest;
use App\Http\Requests\Admin\UpdateInventoryOptionsRequest;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\ServiceParameterOption;
use App\Services\InventoryService;
use App\Services\ProcurementAdvisorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly ProcurementAdvisorService $procurementAdvisor,
    ) {}

    /**
     * Display a listing of the inventory items.
     */
    public function index(Request $request): Response
    {
        $rawHorizon = $request->query('horizon');
        $horizon = is_scalar($rawHorizon) ? (int) $rawHorizon : ProcurementAdvisorService::DEFAULT_HORIZON_DAYS;
        if (! in_array($horizon, [30, 60, 90], true)) {
            $horizon = ProcurementAdvisorService::DEFAULT_HORIZON_DAYS;
        }

        return Inertia::render('Admin/Inventory/Index', [
            'items' => fn () => InventoryItem::with('category')->where('is_active', true)
                ->orderBy('sort_order')
                ->get(),
            'parameterOptions' => fn () => ServiceParameterOption::with('group.service.category')
                ->get()
                ->filter(fn ($opt) => $opt->group && $opt->group->service)
                ->map(fn ($opt) => [
                    'id'                    => $opt->id,
                    'name'                  => $opt->name,
                    'group_name'            => $opt->group->name,
                    'service_name'          => $opt->group->service->name,
                    'service_category_name' => $opt->group->service->category?->name ?? 'Інше',
                    'service_category_sort' => $opt->group->service->category?->sort_order ?? 999,
                    'inventory_item_id'     => $opt->inventory_item_id,
                    'inventory_qty'         => (float) $opt->inventory_qty,
                ])->values(),
            'categories' => fn () => InventoryCategory::where('is_active', true)
                ->orderBy('sort_order')
                ->get(),
            'procurement' => Inertia::defer(fn () => $this->procurementAdvisor->advise($horizon)),
        ]);
    }

    /**
     * Store a newly created inventory item.
     */
    public function store(StoreInventoryItemRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $maxSort = InventoryItem::where('inventory_category_id', $validated['inventory_category_id'])
            ->max('sort_order') ?? 0;

        InventoryItem::create([
            ...$validated,
            'current_quantity' => 0,
            'avg_cost'         => 0,
            'sort_order'       => $maxSort + 1,
        ]);

        return back()->with('success', 'Товар успішно додано на склад.');
    }

    /**
     * Update the specified inventory item.
     */
    public function update(StoreInventoryItemRequest $request, InventoryItem $item): RedirectResponse
    {
        $validated = $request->validated();

        $oldAvgCost = (float) $item->avg_cost;

        $item->update($validated);

        // Refresh cost_markup on all Constructor options linked to this inventory item
        // when avg_cost changes (same pattern as receiveStock in InventoryService)
        if ((float) $item->avg_cost !== $oldAvgCost) {
            ServiceParameterOption::where('inventory_item_id', $item->id)
                ->cursor()
                ->each(fn ($opt) => $opt->save()); // triggers saving hook → recomputes cost_markup
        }

        return back()->with('success', 'Товар оновлено.');
    }

    /**
     * Process a stock receipt (Прибуткова накладна).
     */
    public function receipt(StoreInventoryReceiptRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $item = InventoryItem::findOrFail($validated['inventory_item_id']);

        $totalUnits = $validated['packs_quantity'] * $validated['units_per_pack'];
        $totalCost = $validated['packs_quantity'] * $validated['price_per_pack'];

        $this->inventoryService->receiveStock(
            item: $item,
            quantity: $totalUnits,
            totalCost: $totalCost,
            user: $request->user(),
            notes: $validated['notes'] ?? 'Прихід від постачальника (конвертація з пачок)'
        );

        // Auto-update cost_markup via saving hook (if selected)
        // Uses save() to trigger ServiceParameterOption::saving() → computeCost()
        // which correctly calculates: (avg_cost × inventory_qty) + (click_cost × clicks_per_unit)
        if ($validated['auto_update_markup'] ?? false) {
            $item->refresh();
            ServiceParameterOption::where('inventory_item_id', $item->id)
                ->cursor()
                ->each(fn ($opt) => $opt->save());
        }

        return back()->with('success', "Оприбутковано {$totalUnits} {$item->unit}. Нова собівартість: {$item->avg_cost} грн.");
    }

    /**
     * Update links between service options and inventory items.
     */
    public function updateOptions(UpdateInventoryOptionsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        foreach ($data['options'] as $opt) {
            $option = ServiceParameterOption::find($opt['id']);
            if ($option) {
                $option->fill([
                    'inventory_item_id' => $opt['inventory_item_id'],
                    'inventory_qty'     => $opt['inventory_qty'],
                ]);
                $option->save(); // triggers saving hook → recomputes cost_markup
            }
        }

        return back()->with('success', 'Зв\'язки з послугами оновлено.');
    }

    /**
     * Reorder inventory items (drag & drop).
     */
    public function reorder(ReorderInventoryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        foreach ($data['ids'] as $index => $id) {
            InventoryItem::where('id', $id)->update(['sort_order' => $index + 1]);
        }

        return back()->with('success', 'Порядок оновлено.');
    }

    /**
     * Convert stock from one item to another (e.g. cut A3 → A4).
     */
    public function convert(StoreInventoryConversionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $source = InventoryItem::findOrFail($data['source_item_id']);
        $target = InventoryItem::findOrFail($data['target_item_id']);

        try {
            $this->inventoryService->convertStock(
                source: $source,
                target: $target,
                sourceQuantity: (float) $data['quantity'],
                ratio: (float) $data['ratio'],
                user: $request->user(),
                notes: $data['notes'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $targetQty = (float) $data['quantity'] * (float) $data['ratio'];

        return back()->with('success',
            "Конвертовано {$data['quantity']} {$source->unit} «{$source->name}» → {$targetQty} {$target->unit} «{$target->name}»."
        );
    }

    /**
     * Install a toner cartridge (Списати в роботу).
     */
    public function install(StoreTonerInstallRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $item = InventoryItem::findOrFail($validated['inventory_item_id']);

        try {
            $this->inventoryService->installToner(
                item: $item,
                quantity: (float) $validated['quantity'],
                user: $request->user(),
                notes: $validated['notes'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            // Keyed on `quantity`, not flashed. See refill().
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        return back()->with('success', "Картридж «{$item->name}» встановлено в роботу.");
    }

    /**
     * Refill empty cartridges (Заправити).
     */
    public function refill(StoreTonerRefillRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $item = InventoryItem::findOrFail($validated['inventory_item_id']);

        try {
            $this->inventoryService->refillToner(
                item: $item,
                quantity: (float) $validated['quantity'],
                totalCost: (float) $validated['total_cost'],
                user: $request->user(),
                notes: $validated['notes'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            // Keyed on `quantity` rather than flashed.
            //
            // These three toner forms were on the silent list, and the round
            // that came to fix them found the list had the wrong reason. Their
            // `required` and `min:` rules are all blocked by the client, so
            // the only refusal an operator ever meets here is this one: not
            // enough empties, not enough on the shelf, the count would go
            // below zero. It already names both numbers — «є 1, спроба
            // заправити 5» — and it arrived as a toast that disappeared after
            // twelve seconds, attached to nothing.
            //
            // As a validation error it lands under the quantity field, stays
            // until the next attempt, and the banner still carries it for
            // anyone who missed the modal.
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        return back()->with('success', "Заправлено {$validated['quantity']} картриджів «{$item->name}».");
    }

    /**
     * Adjust empty cartridge quantity directly.
     */
    public function adjustEmpty(AdjustEmptyTonerRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $item = InventoryItem::findOrFail($validated['inventory_item_id']);

        try {
            $this->inventoryService->adjustEmptyToner(
                item: $item,
                quantityChange: (float) $validated['quantity_change'],
                user: $request->user(),
                notes: $validated['notes'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            // Keyed on `quantity_change` — the field it is about. See refill().
            throw ValidationException::withMessages(['quantity_change' => $e->getMessage()]);
        }

        $dir = $validated['quantity_change'] >= 0 ? 'збільшено' : 'зменшено';
        $abs = abs((float) $validated['quantity_change']);

        return back()->with('success', "Кількість порожніх картриджів «{$item->name}» {$dir} на {$abs} шт.");
    }
}
