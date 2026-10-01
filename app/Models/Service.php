<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CounterType;
use App\Enums\ServiceType;
use App\Models\Concerns\InvalidatesReferenceCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Service Model
 *
 * Represents a service in the price list.
 * Two types:
 *   - static: fixed price, direct click mapping
 *   - constructor: dynamic pricing via parameter groups and options
 *
 * @property int $id
 * @property string $name
 * @property ServiceType $type
 * @property float $base_price_commercial
 * @property float $base_price_cost
 * @property CounterType $counter_type Only for static services
 * @property int $clicks_per_unit Only for static services
 * @property bool $is_active
 */
class Service extends Model
{
    use HasFactory;
    use InvalidatesReferenceCache;
    use SoftDeletes;

    protected static string $referenceCacheKey = 'ref:services';

    protected $fillable = [
        'service_category_id',
        'name',
        'type',
        'base_price_commercial',
        'base_price_cost',
        'counter_type',
        'clicks_per_unit',
        'is_active',
    ];

    protected $casts = [
        'type'                  => ServiceType::class,
        'counter_type'          => CounterType::class,
        'base_price_commercial' => 'decimal:2',
        'base_price_cost'       => 'decimal:2',
        'clicks_per_unit'       => 'integer',
        'is_active'             => 'boolean',
    ];

    // ─── Relationships ───────────────────────────────────

    /**
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function parameterGroups(): HasMany
    {
        return $this->hasMany(ServiceParameterGroup::class)->orderBy('sort_order');
    }

    // ─── Helpers ─────────────────────────────────────────

    public function isConstructor(): bool
    {
        return $this->type->isConstructor();
    }

    public function isStatic(): bool
    {
        return $this->type === ServiceType::Static;
    }

    public function isRiso(): bool
    {
        return $this->type->isRiso();
    }

    public function isBrochure(): bool
    {
        return $this->type->isBrochure();
    }

    public function isDiploma(): bool
    {
        return $this->type->isDiploma();
    }
}
