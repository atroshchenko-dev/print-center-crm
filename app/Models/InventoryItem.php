<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property-read InventoryCategory|null $category
 * @property-read InventoryItem|null $convertibleFrom
 */
class InventoryItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'inventory_category_id',
        'subcategory',
        'name',
        'unit',
        'current_quantity',
        'empty_quantity',
        'avg_cost',
        'refill_cost',
        'min_quantity',
        'convertible_from_id',
        'conversion_ratio',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'inventory_category_id' => 'integer',
        'current_quantity'      => 'decimal:4',
        'empty_quantity'        => 'decimal:4',
        'avg_cost'              => 'decimal:4',
        'refill_cost'           => 'decimal:4',
        'min_quantity'          => 'decimal:4',
        'convertible_from_id'   => 'integer',
        'conversion_ratio'      => 'decimal:2',
        'is_active'             => 'boolean',
        'sort_order'            => 'integer',
    ];

    // ─── Relationships ───────────────────────────────────

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'inventory_category_id');
    }

    /**
     * Source item this can be converted FROM (e.g. A3 paper for an A4 item).
     *
     * @return BelongsTo<self, $this>
     */
    public function convertibleFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'convertible_from_id');
    }

    /**
     * Items that can be converted TO from this item (e.g. A4 items from this A3).
     *
     * @return HasMany<self, $this>
     */
    public function convertibleTo(): HasMany
    {
        return $this->hasMany(self::class, 'convertible_from_id');
    }
}
