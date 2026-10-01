<?php

declare(strict_types=1);

use App\Http\Controllers\Admin;
use App\Http\Controllers\Admin\CounterAdjustmentController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\ServiceParameterGroupController;
use App\Http\Controllers\Admin\ServiceParameterOptionController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConstructorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeployController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\ShiftController;
use App\Http\Middleware\EnsureShiftIsOpen;
use Illuminate\Support\Facades\Route;

// ─── Deploy Webhook (GitHub → auto-deploy) ──────────
Route::post('deploy/webhook', DeployController::class)
    ->middleware('throttle:10,1');
Route::get('deploy/test', [DeployController::class, 'test'])
    ->middleware(['auth', 'role:admin']);

// ─── Health Check ────────────────────────────────────
// Admin-only: this reports PHP and Laravel versions, free disk, failed-job
// counts, and — when a dependency is down — the raw exception message, which
// for PDO carries the host and database name. An operator has no use for any
// of it. /ping stays public for UptimeRobot and says only ok/down.
Route::get('health', HealthController::class)
    ->middleware(['auth', 'role:admin', 'throttle:30,1']);
// `/ping` is NOT here. It is registered in bootstrap/app.php, outside the web
// group, so that the monitor's request starts no session — see the comment
// there (audit round 22, §3.2).

// ─── Public Price List ───────────────────────────────
Route::get('price-list', [PriceListController::class, 'index'])
    ->middleware('throttle:30,1')
    ->name('price-list');

// ─── Order Approvals (signed URLs, no auth) ──────────
Route::prefix('approvals')->middleware('throttle:20,1')->group(function () {
    Route::get('/{token}', [ApprovalController::class, 'show'])
        ->name('approvals.show')
        ->middleware('signed');
    Route::post('/{token}/respond', [ApprovalController::class, 'respond'])
        ->name('approvals.respond')
        ->middleware('signed');
});

// ─── Public ──────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.submit');

    // Password Reset (TZ §2.1)
    // Both POSTs are unauthenticated: one sends mail, the other guesses tokens.
    // Laravel's own throttle on sendResetLink is per-address, so it does nothing
    // against someone walking a list of addresses.
    Route::get('forgot-password', [AuthController::class, 'forgotPassword'])->name('password.request');
    Route::post('forgot-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('reset-password/{token}', [AuthController::class, 'showResetForm'])->name('password.reset');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
});

Route::post('logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// ─── Authenticated ────────────────────────────────────
Route::middleware(['auth', 'throttle:60,1'])->group(function () {

    // Dashboard (redirects based on shift status)
    Route::get('/', DashboardController::class)->name('dashboard');

    // ─── Shifts ──────────────────────────────────────
    Route::prefix('shifts')->name('shifts.')->group(function () {
        Route::get('/open', [ShiftController::class, 'openForm'])->name('open.form');
        Route::post('/open', [ShiftController::class, 'open'])->name('open');

        Route::middleware(EnsureShiftIsOpen::class)->group(function () {
            Route::get('/close', [ShiftController::class, 'closeForm'])->name('close.form');
            Route::post('/close', [ShiftController::class, 'close'])->name('close');
        });
    });

    // ─── Constructor Calculate ───
    // No open shift required: both live orders and retro (admin-only) use it, and
    // retro is entered when no shift is open. The 'orders' permission still applies —
    // the response carries cost price, and admins pass it without holding the module.
    Route::post('/constructor/calculate', [ConstructorController::class, 'calculate'])
        ->middleware('permission:orders')
        ->name('constructor.calculate');

    // ─── Orders & Ledger (requires open shift + module permission) ───────
    Route::middleware(EnsureShiftIsOpen::class)->group(function () {

        // Orders — requires 'orders' permission
        Route::middleware('permission:orders')->group(function () {
            Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
            Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
            Route::patch('/orders/batch-status', [OrderController::class, 'batchUpdateStatus'])->middleware('throttle:10,1')->name('orders.batch-status');
            Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
            Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
            Route::patch('/orders/{order}/request', [OrderController::class, 'toggleRequestReceived'])->name('orders.request');
            Route::get('/orders/{order}/edit', [OrderController::class, 'edit'])->name('orders.edit');
            Route::put('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
            Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->middleware('role:admin')->name('orders.destroy');
            Route::post('/orders/{order}/send-approval', [OrderController::class, 'sendApproval'])->name('orders.send-approval');

            // Constructor template (part of orders workflow)
            Route::get('/constructor/{service}', [ConstructorController::class, 'template'])->name('constructor.template');
        });

        // Ledger — requires 'ledger' permission
        Route::middleware('permission:ledger')->group(function () {
            Route::get('/ledger/history', [LedgerController::class, 'history'])->name('ledger.history');
            Route::post('/ledger/withdrawal', [LedgerController::class, 'withdrawal'])->name('ledger.withdrawal');
        });
    });

    // ─── Admin Panel ──────────────────────────────────
    Route::prefix('admin')->name('admin.')->group(function () {

        // Services (price list) — requires 'services' permission
        Route::middleware('permission:services')->group(function () {
            Route::get('/services', [Admin\ServiceController::class, 'index'])->name('services.index');
            // No GET /services/create: services are created by the modal on the
            // index page, which posts straight to services.store. The standalone
            // form page is edit-only (see Admin/Services/Form.vue).
            Route::post('/services', [Admin\ServiceController::class, 'store'])->name('services.store');
            Route::get('/services/{service}/edit', [Admin\ServiceController::class, 'edit'])->name('services.edit-page');
            Route::patch('/services/{service}', [Admin\ServiceController::class, 'update'])->name('services.update');
            Route::delete('/services/{service}', [Admin\ServiceController::class, 'destroy'])->name('services.destroy');

            // Parameter Groups
            Route::post('/services/{service}/groups', [ServiceParameterGroupController::class, 'store'])->name('service-groups.store');
            Route::patch('/service-groups/{group}', [ServiceParameterGroupController::class, 'update'])->name('service-groups.update');
            Route::delete('/service-groups/{group}', [ServiceParameterGroupController::class, 'destroy'])->name('service-groups.destroy');

            // Parameter Options
            Route::post('/service-groups/{group}/options', [ServiceParameterOptionController::class, 'store'])->name('service-options.store');
            Route::patch('/service-options/{option}', [ServiceParameterOptionController::class, 'update'])->name('service-options.update');
            Route::delete('/service-options/{option}', [ServiceParameterOptionController::class, 'destroy'])->name('service-options.destroy');

            // Service Categories
            Route::get('/service-categories', [Admin\ServiceCategoryController::class, 'index'])->name('service-categories.index');
            Route::post('/service-categories', [Admin\ServiceCategoryController::class, 'store'])->name('service-categories.store');
            Route::patch('/service-categories/{category}', [Admin\ServiceCategoryController::class, 'update'])->name('service-categories.update');
            Route::delete('/service-categories/{category}', [Admin\ServiceCategoryController::class, 'destroy'])->name('service-categories.destroy');
            Route::post('/service-categories/reorder', [Admin\ServiceCategoryController::class, 'reorder'])->name('service-categories.reorder');

            // Materials (click cost)
            Route::get('/materials', [Admin\MaterialController::class, 'index'])->name('materials.index');
            Route::post('/materials', [Admin\MaterialController::class, 'store'])->name('materials.store');
            Route::patch('/materials/{material}', [Admin\MaterialController::class, 'update'])->name('materials.update');
            Route::delete('/materials/{material}', [Admin\MaterialController::class, 'destroy'])->name('materials.destroy');

            // Riso Pricing Tiers
            Route::get('/riso-pricing', [Admin\RisoPricingController::class, 'index'])->name('riso-pricing.index');
            Route::post('/riso-pricing', [Admin\RisoPricingController::class, 'store'])->name('riso-pricing.store');
            Route::patch('/riso-pricing', [Admin\RisoPricingController::class, 'update'])->name('riso-pricing.update');
            Route::delete('/riso-pricing/{tier}', [Admin\RisoPricingController::class, 'destroy'])->name('riso-pricing.destroy');
        });

        // Equipment — requires 'equipment' permission
        Route::middleware('permission:equipment')->group(function () {
            Route::get('/equipment', [Admin\EquipmentController::class, 'index'])->name('equipment.index');
            Route::post('/equipment', [Admin\EquipmentController::class, 'store'])->name('equipment.store');
            Route::patch('/equipment/{equipment}', [Admin\EquipmentController::class, 'update'])->name('equipment.update');
            Route::delete('/equipment/{equipment}', [Admin\EquipmentController::class, 'destroy'])->name('equipment.destroy');

            // Counter Adjustment (TZ §3.4)
            Route::post('/equipment/adjust-counter', [CounterAdjustmentController::class, 'adjust'])->name('equipment.adjust');
        });

        // Inventory — requires 'inventory' permission
        Route::middleware('permission:inventory')->group(function () {
            Route::get('/inventory', [Admin\InventoryController::class, 'index'])->name('inventory.index');
            Route::post('/inventory', [Admin\InventoryController::class, 'store'])->name('inventory.store');
            Route::patch('/inventory/{item}', [Admin\InventoryController::class, 'update'])->name('inventory.update');
            Route::post('/inventory/receipt', [Admin\InventoryController::class, 'receipt'])->name('inventory.receipt');
            Route::post('/inventory/options', [Admin\InventoryController::class, 'updateOptions'])->name('inventory.options');
            Route::post('/inventory/reorder', [Admin\InventoryController::class, 'reorder'])->name('inventory.reorder');
            Route::post('/inventory/convert', [Admin\InventoryController::class, 'convert'])->name('inventory.convert');
            Route::post('/inventory/install', [Admin\InventoryController::class, 'install'])->name('inventory.install');
            Route::post('/inventory/refill', [Admin\InventoryController::class, 'refill'])->name('inventory.refill');
            Route::post('/inventory/adjust-empty', [Admin\InventoryController::class, 'adjustEmpty'])->name('inventory.adjust_empty');
        });

        // University — requires 'university' permission
        Route::middleware('permission:university')->group(function () {
            Route::get('/university', [Admin\UniversityController::class, 'index'])->name('university.index');
            // Signatory Groups
            Route::post('/university/groups', [Admin\UniversityController::class, 'storeGroup'])->name('university.groups.store');
            Route::patch('/university/groups/{group}', [Admin\UniversityController::class, 'updateGroup'])->name('university.groups.update');
            Route::delete('/university/groups/{group}', [Admin\UniversityController::class, 'destroyGroup'])->name('university.groups.destroy');
            // Signatories
            Route::post('/university/signatories', [Admin\UniversityController::class, 'storeSignatory'])->name('university.signatories.store');
            Route::patch('/university/signatories/{signatory}', [Admin\UniversityController::class, 'updateSignatory'])->name('university.signatories.update');
            Route::delete('/university/signatories/{signatory}', [Admin\UniversityController::class, 'destroySignatory'])->name('university.signatories.destroy');
            // Departments
            Route::post('/university/departments', [Admin\UniversityController::class, 'storeDepartment'])->name('university.departments.store');
            Route::patch('/university/departments/{department}', [Admin\UniversityController::class, 'updateDepartment'])->name('university.departments.update');
            Route::delete('/university/departments/{department}', [Admin\UniversityController::class, 'destroyDepartment'])->name('university.departments.destroy');
            // Signatory ↔ cost centre pairs
            //
            // `index()` lists signatories `withTrashed()` and their pairs
            // without filtering deactivated departments, so this screen is
            // reachable for a deactivated signatory or a deactivated cost
            // centre — exactly the case a wrong pair needs removing from.
            // `->withTrashed()` lets the implicit `{signatory}`/`{department}`
            // bindings resolve those rows instead of 404ing on them.
            Route::post('/university/signatories/{signatory}/cost-centers', [Admin\UniversityController::class, 'storeCostCenter'])->name('university.signatories.cost-centers.store')->withTrashed();
            Route::delete('/university/signatories/{signatory}/cost-centers/{department}', [Admin\UniversityController::class, 'destroyCostCenter'])->name('university.signatories.cost-centers.destroy')->withTrashed();
            // Cost centre ↔ initiator
            Route::post('/university/departments/{department}/initiators', [Admin\UniversityController::class, 'storeInitiator'])->name('university.departments.initiators.store')->withTrashed();
            Route::delete('/university/departments/{department}/initiators/{initiator}', [Admin\UniversityController::class, 'destroyInitiator'])->name('university.departments.initiators.destroy')->withTrashed();
        });

        // Users — admin only. The 'users' module permission is NOT sufficient:
        // it allows creating an account with role=admin and a chosen password,
        // which escalates to full access regardless of the self-elevation guard.
        Route::middleware('role:admin')->group(function () {
            Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');
            Route::post('/users', [Admin\UserController::class, 'store'])->name('users.store');
            Route::patch('/users/{user}', [Admin\UserController::class, 'update'])->name('users.update');
            Route::delete('/users/{user}', [Admin\UserController::class, 'destroy'])->name('users.destroy');
            Route::patch('/users/{id}/restore', [Admin\UserController::class, 'restore'])->name('users.restore');
        });

        // Reconciliation — requires 'reports' permission
        Route::middleware('permission:reports')->group(function () {
            Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
            Route::get('/reconciliation/export', [ReconciliationController::class, 'export'])->name('reconciliation.export');
            Route::patch('/reconciliation/{order}', [ReconciliationController::class, 'reconcile'])->name('reconciliation.reconcile');
            Route::post('/reconciliation/batch', [ReconciliationController::class, 'reconcileBatch'])->name('reconciliation.batch');
            Route::delete('/reconciliation/{order}', [ReconciliationController::class, 'unreconcile'])->name('reconciliation.unreconcile');
            Route::patch('/reconciliation/{order}/request', [ReconciliationController::class, 'toggleRequest'])->name('reconciliation.toggle-request');
            Route::delete('/reconciliation/{order}/destroy', [ReconciliationController::class, 'destroy'])->middleware('role:admin')->name('reconciliation.destroy');
            Route::post('/reconciliation/close-month', [ReconciliationController::class, 'closeMonth'])->name('reconciliation.close-month');
        });

        // Settings — admin only (backend-enforced)
        Route::middleware('role:admin')->group(function () {
            Route::get('/settings', [Admin\SettingsController::class, 'index'])->name('settings.index');
            Route::post('/settings/toggle-cache', [Admin\SettingsController::class, 'toggleCache'])->name('settings.toggle-cache');
            Route::post('/settings/payment-method/{method}', [Admin\SettingsController::class, 'togglePaymentMethod'])->name('settings.toggle-payment-method');
            Route::post('/settings/toggle-telegram', [Admin\SettingsController::class, 'toggleTelegram'])->name('settings.toggle-telegram');
            Route::post('/settings/numeric', [Admin\SettingsController::class, 'updateNumericSetting'])->name('settings.update-numeric');

            // Backdated Orders (Ретро-замовлення)
            Route::get('/backdated-orders', [Admin\BackdatedOrderController::class, 'index'])->name('backdated-orders.index');
            Route::get('/backdated-orders/create', [Admin\BackdatedOrderController::class, 'create'])->name('backdated-orders.create');
            Route::get('/backdated-orders/export', [Admin\BackdatedOrderController::class, 'export'])->name('backdated-orders.export');
            Route::post('/backdated-orders', [Admin\BackdatedOrderController::class, 'store'])->name('backdated-orders.store');
            Route::patch('/backdated-orders/{order}/reconcile', [Admin\BackdatedOrderController::class, 'reconcile'])->name('backdated-orders.reconcile');
            Route::post('/backdated-orders/reconcile-batch', [Admin\BackdatedOrderController::class, 'reconcileBatch'])->name('backdated-orders.reconcile-batch');
            Route::delete('/backdated-orders/{order}/reconcile', [Admin\BackdatedOrderController::class, 'unreconcile'])->name('backdated-orders.unreconcile');
            Route::patch('/backdated-orders/{order}/request', [Admin\BackdatedOrderController::class, 'toggleRequest'])->name('backdated-orders.toggle-request');
            Route::get('/backdated-orders/{order}/edit', [Admin\BackdatedOrderController::class, 'edit'])->name('backdated-orders.edit');
            Route::put('/backdated-orders/{order}', [Admin\BackdatedOrderController::class, 'update'])->name('backdated-orders.update');
            Route::delete('/backdated-orders/{order}', [Admin\BackdatedOrderController::class, 'destroy'])->name('backdated-orders.destroy');
        });
    });

    // ─── Reports ────────────────────────────────────
    Route::prefix('reports')->name('reports.')->middleware('permission:reports')->group(function () {
        Route::get('/internal', [ReportController::class, 'internal'])->name('internal');
        Route::get('/commercial', [ReportController::class, 'commercial'])->name('commercial');
        Route::get('/cash-flow', [ReportController::class, 'cashFlow'])->name('cash-flow');
        Route::get('/counters', [ReportController::class, 'counters'])->name('counters');
        Route::get('/audit', [ReportController::class, 'audit'])->name('audit');

        // XLSX Export (via ReportExportController)
        Route::get('/internal/export', [ReportExportController::class, 'internal'])->name('internal.export');
        Route::get('/commercial/export', [ReportExportController::class, 'commercial'])->name('commercial.export');
        Route::get('/cash-flow/export', [ReportExportController::class, 'cashFlow'])->name('cash-flow.export');
        Route::get('/counters/export', [ReportExportController::class, 'counters'])->name('counters.export');
        Route::get('/audit/export', [ReportExportController::class, 'audit'])->name('audit.export');
    });

    // ─── Analytics ──────────────────────────────────
    Route::get('/analytics', [AnalyticsController::class, 'index'])
        ->middleware('permission:reports')
        ->name('analytics');
});
