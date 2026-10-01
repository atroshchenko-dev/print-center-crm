<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCostCenterInitiatorRequest;
use App\Http\Requests\Admin\SaveDepartmentRequest;
use App\Http\Requests\Admin\SaveSignatoryCostCenterRequest;
use App\Http\Requests\Admin\SaveSignatoryGroupRequest;
use App\Http\Requests\Admin\SaveSignatoryRequest;
use App\Models\CostCenterInitiator;
use App\Models\Department;
use App\Models\ServiceCategory;
use App\Models\SignatoryCostCenter;
use App\Models\SignatoryGroup;
use App\Models\UniversityRef;
use App\Services\ReferenceDataService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UniversityController extends Controller
{
    // ─── Index (all tabs) ──────────────────────────────

    /**
     * All three tabs, deactivated rows included.
     *
     * The page has been drawing a «Видалено» badge, greying the row and hiding
     * its buttons since it was written, and none of it could ever render: the
     * three queries below carried the soft-delete scope, so a row deactivated by
     * the «Видалити» button simply vanished. The message says «деактивовано»,
     * the record is still there, and the admin had no way to see either — nor
     * that a signatory who disappeared from the dropdown still exists. The
     * service-categories screen already lists its trashed rows; this is the same
     * contract, and the page was built for it (`activeGroups` filters
     * `!g.deleted_at`, which is only meaningful if such rows arrive).
     *
     * The group relation is loaded withTrashed for the same reason: a signatory
     * in a deactivated group was reading as «Без групи», which is not what
     * happened to them.
     */
    public function index(): Response
    {
        return Inertia::render('Admin/University/Index', [
            // monthly_limit alongside daily_limit, because the daily figure is
            // an input now and the pool is the rule the server applies. Set
            // here rather than appended on the model: this page is the only
            // consumer, and it depends on the current Kyiv month.
            'signatoryGroups' => SignatoryGroup::withTrashed()
                ->with('categories:id,name')
                ->withCount('signatories')
                ->orderBy('sort_order')
                ->get()
                ->each(fn (SignatoryGroup $group) => $group->setAttribute('monthly_limit', $group->monthlyLimit())),
            'signatories' => UniversityRef::withTrashed()
                ->with([
                    'group' => fn ($q) => $q->withTrashed()->select('id', 'name', 'deleted_at'),
                    // Пари накопичуються самі, тож єдине місце, де їх видно
                    // й можна поправити — цей екран.
                    //
                    // `department` — withTrashed із тієї ж причини, що й `group`
                    // вище: підрозділ можна деактивувати, а пара з ним лишається
                    // в таблиці. Під глобальним скоупом така пара приїжджала
                    // з `'name' => null`, і екран малював порожній рядок із
                    // хрестиком — на єдиному екрані, який існує, щоб такі пари
                    // прибирати, адміністратор не бачив, що саме прибирає.
                    'costCenters' => fn ($q) => $q
                        ->with(['department' => fn ($d) => $d->withTrashed()->select('id', 'name')])
                        ->orderByDesc('orders_count'),
                ])
                ->latest()
                ->get()
                ->each(function (UniversityRef $signatory) {
                    $centers = $signatory->costCenters->map(fn (SignatoryCostCenter $pair) => [
                        'id'            => $pair->id,
                        'department_id' => $pair->department_id,
                        'name'          => $pair->department?->name,
                        'orders_count'  => $pair->orders_count,
                        'last_used_at'  => $pair->last_used_at?->toDateString(),
                    ])->values();

                    // `costCenters` — це relation-ключ, і Eloquent серіалізує
                    // його як `cost_centers` (snake_case), тим самим
                    // перезаписуючи атрибут нижче в array_merge всередині
                    // toArray(): relationsToArray() йде другим і виграє.
                    // Без unsetRelation() пропс ніс би сирі моделі
                    // SignatoryCostCenter (без top-level `name`) замість
                    // мапованого масиву, і тест на `cost_centers.0.name` бив
                    // би по неіснуючому ключу.
                    $signatory->unsetRelation('costCenters');
                    $signatory->setAttribute('cost_centers', $centers);
                }),
            'departments' => Department::withTrashed()
                ->with(['initiators' => fn ($q) => $q->orderByDesc('orders_count')->orderBy('name')])
                ->latest()
                ->get()
                ->each(function (Department $department) {
                    $names = $department->initiators->map(fn (CostCenterInitiator $row) => [
                        'id'           => $row->id,
                        'name'         => $row->name,
                        'orders_count' => $row->orders_count,
                        'last_used_at' => $row->last_used_at?->toDateString(),
                    ])->values();

                    // Знімається перед тим, як виставити атрибут: Eloquent
                    // snake-кейсить ім'я зв'язку (`initiators`) і зливає його
                    // в масив **після** атрибутів, тобто перезаписав би
                    // порахований список сирими моделями. Той самий дефект уже
                    // ловили на `costCenters` у попередньому раунді.
                    $department->unsetRelation('initiators');
                    $department->setAttribute('initiators', $names);
                }),
            'serviceCategories' => ServiceCategory::where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name']),
        ]);
    }

    // ─── Signatory Groups CRUD ─────────────────────────

    public function storeGroup(SaveSignatoryGroupRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $group = SignatoryGroup::create([
            'name'        => $data['name'],
            'daily_limit' => $data['daily_limit'] ?? null,
            'is_active'   => $data['is_active'] ?? true,
            'sort_order'  => SignatoryGroup::max('sort_order') + 10,
        ]);

        $group->categories()->sync($data['category_ids'] ?? []);

        // `ref:signatories` caches group.categories for an hour, and that set is
        // what draws the category tabs on the order forms. `sync()` fires no
        // model event, so no trait can catch this — same reason the cost-centre
        // handlers below flush by hand.
        app(ReferenceDataService::class)->flush();

        return back()->with('success', 'Групу створено.');
    }

    public function updateGroup(SaveSignatoryGroupRequest $request, SignatoryGroup $group): RedirectResponse
    {
        $data = $request->validated();

        $group->update([
            'name'        => $data['name'],
            'daily_limit' => $data['daily_limit'] ?? null,
            'is_active'   => $data['is_active'] ?? true,
        ]);

        $group->categories()->sync($data['category_ids'] ?? []);

        app(ReferenceDataService::class)->flush();

        return back()->with('success', 'Групу оновлено.');
    }

    public function destroyGroup(SignatoryGroup $group): RedirectResponse
    {
        $group->delete();

        app(ReferenceDataService::class)->flush();

        return back()->with('success', 'Групу деактивовано.');
    }

    // ─── Signatories CRUD ───────────────────────────────

    public function storeSignatory(SaveSignatoryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        UniversityRef::create($data);

        return back()->with('success', 'Уповноважену особу додано.');
    }

    public function updateSignatory(SaveSignatoryRequest $request, UniversityRef $signatory): RedirectResponse
    {
        $data = $request->validated();

        $signatory->update($data);

        return back()->with('success', 'Уповноважену особу оновлено.');
    }

    public function destroySignatory(UniversityRef $signatory): RedirectResponse
    {
        $signatory->delete();

        return back()->with('success', 'Уповноважену особу деактивовано.');
    }

    // ─── Departments CRUD ───────────────────────────────

    public function storeDepartment(SaveDepartmentRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Department::create($data);

        return back()->with('success', 'Центр витрат додано.');
    }

    public function updateDepartment(SaveDepartmentRequest $request, Department $department): RedirectResponse
    {
        $data = $request->validated();

        $department->update($data);

        return back()->with('success', 'Центр витрат оновлено.');
    }

    public function destroyDepartment(Department $department): RedirectResponse
    {
        $department->delete();

        return back()->with('success', 'Центр витрат деактивовано.');
    }

    // ─── Signatory ↔ cost centre pairs ──────────────────

    /**
     * Пара, додана наперед: замовлень із нею ще не було, тому `orders_count`
     * лишається нулем. Наявну пару додавання не чіпає — інакше кнопка в адмінці
     * тихо стирала б накопичену частоту.
     *
     * `{signatory}` резолвиться через `->withTrashed()` на маршруті (див.
     * `routes/web.php`): `index()` показує деактивованих підписантів, і кнопка
     * «Центри витрат» для них не ховається.
     */
    public function storeCostCenter(SaveSignatoryCostCenterRequest $request, UniversityRef $signatory): RedirectResponse
    {
        SignatoryCostCenter::firstOrCreate([
            'university_ref_id' => $signatory->id,
            'department_id'     => (int) $request->validated()['department_id'],
        ], [
            'orders_count' => 0,
        ]);

        app(ReferenceDataService::class)->flush();

        return back()->with('success', 'Центр витрат додано підписанту.');
    }

    /**
     * Видалення жорстке. Пара повернеться, якщо хтось знову оформить
     * замовлення з нею: це факт, а не думка.
     *
     * `{signatory}` і `{department}` обидва резолвляться через
     * `->withTrashed()` на маршруті: пара, яку тут прибирають, могла
     * накопичитись до того, як підписанта чи центр витрат деактивували, і
     * саме тоді її найімовірніше треба прибрати.
     */
    public function destroyCostCenter(UniversityRef $signatory, Department $department): RedirectResponse
    {
        $deleted = SignatoryCostCenter::where('university_ref_id', $signatory->id)
            ->where('department_id', $department->id)
            ->delete();

        // Як і destroyInitiator нижче: URL із парою, якої немає, отримує
        // чесний 404, а не звіт про видалення, якого не було.
        abort_unless($deleted > 0, 404);

        app(ReferenceDataService::class)->flush();

        return back()->with('success', 'Центр витрат відв\'язано.');
    }

    // ─── Cost centre ↔ initiator ────────────────────────

    /**
     * Ім'я, додане наперед: замовлень із ним ще не було, тому `orders_count`
     * лишається нулем. Наявний рядок не чіпається — інакше кнопка тихо стирала б
     * накопичену частоту.
     */
    public function storeInitiator(SaveCostCenterInitiatorRequest $request, Department $department): RedirectResponse
    {
        $name = trim($request->validated()['initiator']);

        CostCenterInitiator::firstOrCreate(
            [
                'department_id' => $department->id,
                'name_key'      => CostCenterInitiator::normalizeKey($name),
            ],
            [
                'name'         => $name,
                'orders_count' => 0,
            ],
        );

        app(ReferenceDataService::class)->flush();

        return back()->with('success', 'Ініціатора додано.');
    }

    /**
     * Видалення жорстке. Ім'я повернеться, якщо хтось знову оформить
     * замовлення з ним: це факт, а не думка.
     *
     * `{initiator}` резолвиться за власним id, тож URL із чужою парою
     * підрозділ/ініціатор технічно проходить біндинг — цей рядок ловить таку
     * невідповідність і каже правду (404), а не звітує про видалення, якого
     * не було.
     */
    public function destroyInitiator(Department $department, CostCenterInitiator $initiator): RedirectResponse
    {
        abort_unless($initiator->department_id === $department->id, 404);

        $initiator->delete();

        app(ReferenceDataService::class)->flush();

        return back()->with('success', 'Ініціатора відв\'язано.');
    }
}
