<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property-read User|null $user
 * @property-read InventoryItem|null $item
 */
class InventoryMovement extends Model
{
    const UPDATED_AT = null; // Append-only

    protected $fillable = [
        'inventory_item_id',
        'type',
        'quantity',
        'empty_quantity',
        'unit_cost',
        'total_cost',
        'reference_type',
        'reference_id',
        'user_id',
        'notes',
    ];

    protected $casts = [
        'quantity'       => 'decimal:4',
        'empty_quantity' => 'decimal:4',
        'unit_cost'      => 'decimal:4',
        'total_cost'     => 'decimal:4',
    ];

    // Append-only guards
    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            $movement->created_at = $movement->created_at ?? now();
        });

        static::updating(function ($model) {
            throw new \RuntimeException('Inventory movements are append-only. Cannot update.');
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('Inventory movements are append-only. Cannot delete.');
        });
    }

    public function forceDelete(): never
    {
        throw new \RuntimeException('Inventory movements are append-only. Cannot delete.');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
