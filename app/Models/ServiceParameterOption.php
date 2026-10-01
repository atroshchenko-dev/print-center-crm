<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CounterType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ServiceParameterOption Model
 *
 * A concrete option within a parameter group (a "chip" in Constructor UI).
 * cost_markup is AUTO-CALCULATED from:
 *   - inventory_item.avg_cost × inventory_qty  (consumables, e.g. paper)
 *   - material.click_cost × clicks_per_unit    (equipment amortization)
 *
 * Manual entry of cost_markup is NOT recommended — use recomputeCost() instead.
 *
 * @property int $id
 * @property int $group_id
 * @property string $name
 * @property float $price_markup        Added to commercial price per unit
 * @property float $cost_markup         AUTO-CALCULATED (consumables + amortization)
 * @property int|null $inventory_item_id
 * @property float $inventory_qty       Units of inventory consumed per service unit
 * @property CounterType $counter_type
 * @property int $clicks_per_unit
 * @property int $sort_order
 * @property bool $is_active
 */
class ServiceParameterOption extends Model
{
    use HasFactory;
    use \App\Models\Concerns\InvalidatesReferenceCache;
    use SoftDeletes;

    /** Constructor prices live here, and they ride inside the services list. */
    protected static string $referenceCacheKey = 'ref:services';

    protected $fillable = [
        'group_id',
        'name',
        'price_markup',
        'cost_markup',
        'inventory_item_id',
        'inventory_qty',
        'counter_type',
        'clicks_per_unit',
        'sort_order',
        'is_active',
        'depends_on',
    ];

    protected $casts = [
        'price_markup'    => 'decimal:4',
        'cost_markup'     => 'decimal:4',
        'inventory_qty'   => 'decimal:4',
        'clicks_per_unit' => 'integer',
        'sort_order'      => 'integer',
        'is_active'       => 'boolean',
        'counter_type'    => CounterType::class,
        'depends_on'      => 'array',
    ];

    // ─── Boot: auto-fill cost_markup on save ─────────────

    protected static function booted(): void
    {
        static::saving(function (self $option): void {
            // Auto-calculate cost_markup only when at least one cost source
            // is configured (inventory link or click counter).
            // For pure services (e.g. scanning) with neither source,
            // preserve the manually-set cost_markup value.
            $hasInventory = $option->inventory_item_id && $option->inventory_qty > 0;
            $hasClicks    = $option->clicks_per_unit > 0
                         && $option->counter_type !== \App\Enums\CounterType::None;

            if ($hasInventory || $hasClicks) {
                $option->cost_markup = $option->computeCost();
            }
        });
    }

    // ─── Relationships ───────────────────────────────────

    public function group(): BelongsTo
    {
        return $this->belongsTo(ServiceParameterGroup::class, 'group_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    // ─── Cost Computation ────────────────────────────────

    /**
     * Auto-calculate cost_markup from two components:
     *   1. Consumables cost:   inventory_item.avg_cost × inventory_qty
     *   2. Amortization cost:  active_material.click_cost × clicks_per_unit
     *
     * Returns 0 if neither source is configured.
     */
    public function computeCost(): float
    {
        $consumables   = $this->computeConsumablesCost();
        $amortization  = $this->computeAmortizationCost();

        return round($consumables + $amortization, 4);
    }

    /**
     * Cost of physical consumables (paper, covers, springs…).
     *
     * If the linked inventory item has avg_cost=0 but has a convertible source
     * (e.g. A3 paper for A4), use source's avg_cost ÷ conversion_ratio as fallback.
     * This ensures pricing reflects actual paper cost before auto-conversion happens.
     */
    public function computeConsumablesCost(): float
    {
        if (! $this->inventory_item_id || $this->inventory_qty <= 0) {
            return 0.0;
        }

        $item = $this->inventoryItem ?? InventoryItem::find($this->inventory_item_id);
        if (! $item) {
            return 0.0;
        }

        $avgCost = (float) $item->avg_cost;

        // Fallback: if item has no cost but can be converted from A3
        if ($avgCost <= 0 && $item->convertible_from_id && $item->conversion_ratio > 0) {
            $source = $item->convertibleFrom;
            if ($source && (float) $source->avg_cost > 0) {
                $avgCost = (float) $source->avg_cost / (float) $item->conversion_ratio;
            }
        }

        return $avgCost * (float) $this->inventory_qty;
    }

    /**
     * Cost of equipment amortization (ink, toner, wear) per click.
     * Looks up the active Material by counter_type (cached per request).
     */
    public function computeAmortizationCost(): float
    {
        if (! $this->clicks_per_unit || $this->counter_type === CounterType::None) {
            return 0.0;
        }

        $counterName = $this->counter_type instanceof CounterType
            ? $this->counter_type->value
            : (string) $this->counter_type;

        // Static cache: materials table is tiny (3 rows), avoids N+1 on bulk save
        static $materialCache = [];
        if (! isset($materialCache[$counterName])) {
            $materialCache[$counterName] = Material::where('is_active', true)
                ->where('counter_type', $counterName)
                ->first();
        }
        $material = $materialCache[$counterName];

        if (! $material) {
            return 0.0;
        }

        return round((float) $material->click_cost * (int) $this->clicks_per_unit, 4);
    }

    /**
     * Structured breakdown of cost components (for UI display).
     *
     * @return array{consumables: float, amortization: float, total: float}
     */
    public function costBreakdown(): array
    {
        $consumables  = $this->computeConsumablesCost();
        $amortization = $this->computeAmortizationCost();

        return [
            'consumables'  => $consumables,
            'amortization' => $amortization,
            'total'        => round($consumables + $amortization, 4),
        ];
    }

    // ─── Helpers ─────────────────────────────────────────

    public function affectsCounter(): bool
    {
        return $this->counter_type !== CounterType::None && $this->clicks_per_unit > 0;
    }
}
