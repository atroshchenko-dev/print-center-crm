<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CostCenterInitiator;
use App\Models\Department;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\RisoPriceTier;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\SignatoryCostCenter;
use App\Models\UniversityRef;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * ReferenceDataService
 *
 * Provides reference data (categories, services, tiers) to controllers.
 * Cached as arrays (not Eloquent models) to avoid __PHP_Incomplete_Class
 * errors with Redis serialization after php artisan optimize.
 *
 * Caching is switched by the `cache_enabled` row of the settings table, which
 * the admin Settings page writes. This header used to point at
 * CACHE_ENABLED=false in .env — a switch that was wired to a config key
 * nothing read, so setting it turned nothing off.
 */
class ReferenceDataService
{
    private const TTL = 3600; // 1 hour

    /**
     * Get active service categories.
     */
    public function categories(): Collection
    {
        $query = fn () => ServiceCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->toBase();

        return $this->cached('ref:categories', $query);
    }

    /**
     * Get active services with parameters.
     */
    public function services(): Collection
    {
        $query = fn () => Service::where('is_active', true)
            ->with(['parameterGroups.options', 'category'])
            ->orderBy('name')
            ->get()
            ->toBase();

        return $this->cached('ref:services', $query);
    }

    /**
     * Get riso price tiers.
     */
    public function risoTiers(): Collection
    {
        $query = fn () => RisoPriceTier::orderBy('min_qty')
            ->get()
            ->toBase();

        return $this->cached('ref:riso_tiers', $query);
    }

    /**
     * Get active departments (cost centers).
     */
    public function departments(): Collection
    {
        $query = fn () => Department::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->toBase();

        return $this->cached('ref:departments', $query);
    }

    /**
     * Get active signatories with groups.
     */
    public function signatories(): Collection
    {
        $query = fn () => UniversityRef::where('is_active', true)
            // `withTrashed()` on the group: an active signatory whose group
            // was deactivated is a supported, deliberately tested state
            // (`SignatoryGroupLimitTest::test_a_deactivated_group_still_*`),
            // and `LimitService::checkSignatoryLimit()` and
            // `servicesOutsideSignatoryCategories()` already resolve the
            // group the same way. Without it here, the client saw
            // `group === null`, offered every category tab, and the server
            // then warned on items the tab list itself had offered.
            ->with(['group' => fn ($q) => $q->withTrashed()->with('categories:id,name')])
            // Список приходив у порядку таблиці, тобто по id: спершу ті, кого
            // завели на старті, далі дописані пізніше. У випадайці за двадцять
            // прізвищ потрібне доводилось шукати очима двічі.
            //
            // Тонке місце: справжня українська абетка (І, Ї, Є, Ґ) цим
            // `orderBy` не досягається — `ext-intl` composer.json не вимагає,
            // і спільного українського collation тут немає. Реальний порядок
            // для випадайок задає `sortSignatories()` у
            // resources/js/utils/sortSignatories.js на фронті; тут —
            // розумний типовий порядок для решти читачів списку.
            ->orderBy('full_name')
            ->get()
            ->toBase();

        return $this->cached('ref:signatories', $query);
    }

    /**
     * Які центри витрат водяться за кожним підписантом, найчастіші першими.
     *
     * Форма підставляє перший елемент, коли він єдиний, тому порядок тут —
     * частина контракту, а не оформлення.
     */
    public function signatoryCostCenters(): Collection
    {
        // `->toBase()` before `->get()`: `department_name` is a join alias,
        // not a column `SignatoryCostCenter` owns, and the rows returned here
        // are never saved back — hydrating them as the model dressed up
        // undeclared aliases as model properties. Plain `stdClass` rows say
        // what they actually are.
        $query = fn () => SignatoryCostCenter::query()
            ->join('departments', 'departments.id', '=', 'signatory_cost_centers.department_id')
            ->whereNull('departments.deleted_at')
            ->where('departments.is_active', true)
            ->orderByDesc('signatory_cost_centers.orders_count')
            ->orderBy('departments.name')
            ->toBase()
            ->get([
                'signatory_cost_centers.university_ref_id',
                'signatory_cost_centers.department_id',
                'departments.name as department_name',
            ])
            ->groupBy('university_ref_id')
            ->map(fn (Collection $rows) => $rows
                ->map(fn ($row) => ['id' => $row->department_id, 'name' => $row->department_name])
                ->values()
                ->all())
            ->toBase();

        return $this->cached('ref:signatory_cost_centers', $query);
    }

    /**
     * Які імена водяться за кожним підрозділом, найчастіші першими.
     *
     * Форма показує їх угорі підказок, тому порядок тут — частина контракту,
     * а не оформлення.
     */
    public function costCenterInitiators(): Collection
    {
        $query = fn () => CostCenterInitiator::query()
            ->join('departments', 'departments.id', '=', 'cost_center_initiators.department_id')
            ->whereNull('departments.deleted_at')
            ->where('departments.is_active', true)
            ->orderByDesc('cost_center_initiators.orders_count')
            ->orderBy('cost_center_initiators.name')
            // `toBase()` до `get()`: рядки з приєднаної таблиці не є
            // CostCenterInitiator, і гідрувати їх у модель — саме та неправда,
            // на яку PHPStan level 5 лається «undefined property».
            ->toBase()
            ->get([
                'cost_center_initiators.department_id',
                'cost_center_initiators.name',
            ])
            ->groupBy('department_id')
            ->map(fn ($rows) => $rows->pluck('name')->values()->all());

        return $this->cached('ref:cost_center_initiators', $query);
    }

    /**
     * Get active paper items for RISO/Brochure/Diploma constructors.
     */
    public function risoPapers(): Collection
    {
        return InventoryItem::where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('name', InventoryCategory::PAPER_SLUG))
            ->orderBy('sort_order')
            ->get(['id', 'name', 'avg_cost', 'unit']);
    }

    /**
     * Get paper A3 cost from inventory AVCO (dynamic).
     * Falls back to config('riso.paper_cost_a3') if item not found.
     */
    public function risoPaperCost(): float
    {
        $item = InventoryItem::where('name', config('riso.default_paper_a3_name'))
            ->where('is_active', true)
            ->first();

        return $item && (float) $item->avg_cost > 0
            ? (float) $item->avg_cost
            : (float) config('riso.paper_cost_a3', 0.80);
    }

    /**
     * Get inventory stock levels for all active items (for stock indicators).
     */
    public function inventoryStock(): Collection
    {
        return InventoryItem::where('is_active', true)
            ->get(['id', 'current_quantity', 'min_quantity', 'convertible_from_id', 'conversion_ratio'])
            ->mapWithKeys(fn ($item) => [
                $item->id => [
                    'qty'        => (float) $item->current_quantity,
                    'min'        => (float) $item->min_quantity,
                    'conv_from'  => $item->convertible_from_id,
                    'conv_ratio' => (float) $item->conversion_ratio,
                ],
            ]);
    }

    /**
     * Get click costs from materials table (for brochure/diploma live pricing).
     *
     * @return array{bw: float, color: float}
     */
    public function clickCosts(): array
    {
        return Material::clickCosts();
    }

    /**
     * Flush reference data caches.
     */
    public function flush(?string $key = null): void
    {
        if ($key) {
            Cache::forget("ref:{$key}");
        } else {
            Cache::forget('ref:categories');
            Cache::forget('ref:services');
            Cache::forget('ref:riso_tiers');
            Cache::forget('ref:departments');
            Cache::forget('ref:signatories');
            Cache::forget('ref:signatory_cost_centers');
            Cache::forget('ref:cost_center_initiators');
        }
    }

    /**
     * Cache wrapper — respects the `cache_enabled` setting in the database.
     */
    private function cached(string $key, \Closure $query): Collection
    {
        if (! $this->isCacheEnabled()) {
            return $query();
        }

        try {
            $result = Cache::remember($key, self::TTL, $query);

            // Guard: stale cache may contain __PHP_Incomplete_Class after deploy
            if (! $result instanceof Collection || $this->hasIncompleteClass($result)) {
                Cache::forget($key);

                return $query();
            }

            return $result;
        } catch (\Throwable) {
            Cache::forget($key);

            return $query();
        }
    }

    /**
     * Check if a collection contains __PHP_Incomplete_Class objects (stale cache).
     */
    private function hasIncompleteClass(Collection $collection): bool
    {
        foreach ($collection as $item) {
            if ($item instanceof \__PHP_Incomplete_Class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if caching is enabled.
     */
    private function isCacheEnabled(): bool
    {
        return Setting::getValue('cache_enabled', true);
    }
}
