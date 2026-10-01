<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\InvalidatesReferenceCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceCategory extends Model
{
    use HasFactory, SoftDeletes;
    use InvalidatesReferenceCache;

    protected static string $referenceCacheKey = 'ref:categories';

    /**
     * The one category whose `material_description` holds a customer's name.
     *
     * Operators type the person the cards are for into that free field, which
     * makes it the only order-item text covered by the retention policy
     * (docs/PII.md §3). Keyed on the category name, like
     * ServiceParameterGroup::PAPER — and with the same hazard: renaming the
     * category in the admin turns the rule off. `PrunePersonalData` logs a
     * warning when the name matches nothing, so it fails loudly rather than
     * quietly keeping the data forever.
     */
    public const BUSINESS_CARDS = 'Візитівки';

    protected $fillable = [
        'name',
        'sort_order',
        'is_active',
        'available_for',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'available_for' => 'array',
    ];

    // ─── Scopes ─────────────────────────────────────────

    /**
     * Filter categories available for a given order type.
     */
    public function scopeAvailableFor(Builder $query, string $orderType): Builder
    {
        return $query->whereJsonContains('available_for', $orderType);
    }

    // ─── Relationships ──────────────────────────────────

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
