<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMaterialRequest;
use App\Models\Material;
use App\Services\AuditService;
use App\Support\KyivClock;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MaterialController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Materials/Index', [
            // The stored value is a UTC instant; the edit field is labelled
            // "Активація (Kyiv)". Handing it the raw value showed the time
            // three hours early, and saving the form converted that displayed
            // value again — so every trip through the form walked the
            // activation another three hours back.
            'materials' => Material::withTrashed()->latest()->get()
                ->map(fn (Material $m) => array_merge($m->toArray(), [
                    'pending_activated_at_local' => KyivClock::toLocalInput($m->pending_activated_at),
                ]))
                ->values(),
        ]);
    }

    public function store(StoreMaterialRequest $request): RedirectResponse
    {
        $data     = $request->validated();
        $material = Material::create($data);

        // The same trail update() leaves. A price change scheduled while the
        // material is being created is still a scheduled price change —
        // PriceSchedulerService activates it on the same terms — but only one of
        // the two routes said so, and the other is the one used for a new
        // material's opening price.
        $this->logScheduledPrice($request->user(), $material, $data);

        return back()->with('success', 'Матеріал додано.');
    }

    public function update(StoreMaterialRequest $request, Material $material): RedirectResponse
    {
        // Already a UTC instant — StoreMaterialRequest converts the Kyiv wall
        // clock before validation, so both this route and store() write the
        // same thing.
        $data = $request->validated();

        $this->logScheduledPrice($request->user(), $material, $data);

        $material->update($data);
        return back()->with('success', 'Матеріал оновлено.');
    }

    /**
     * One audit entry for a scheduled price change, whichever door it came through.
     *
     * @param  array<string, mixed>  $data
     */
    private function logScheduledPrice(?\App\Models\User $user, Material $material, array $data): void
    {
        if (empty($data['pending_click_cost'])) {
            return;
        }

        $this->auditService->log(
            'price_scheduled',
            $user,
            "Material '{$material->name}' price update scheduled: {$data['pending_click_cost']} at {$data['pending_activated_at']}",
        );
    }

    public function destroy(Material $material): RedirectResponse
    {
        $material->delete();
        return back()->with('success', 'Матеріал деактивовано.');
    }
}
