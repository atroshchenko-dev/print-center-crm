<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SignatoryGroup Model
 *
 * Groups university signatories by access level.
 * Each group defines which service categories are available
 * and an optional daily copy limit.
 *
 * @property int $id
 * @property string $name
 * @property int|null $daily_limit
 * @property int $sort_order
 * @property bool $is_active
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ServiceCategory> $categories
 */
class SignatoryGroup extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'daily_limit',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'daily_limit' => 'integer',
        'sort_order'  => 'integer',
        'is_active'   => 'boolean',
    ];

    // ─── Relationships ───────────────────────────────────

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'signatory_group_service_category')
            ->withTimestamps();
    }

    public function signatories(): HasMany
    {
        return $this->hasMany(UniversityRef::class);
    }

    // ─── Limit ───────────────────────────────────────────

    /**
     * What the daily figure is worth over the month it is spent in.
     *
     * `daily_limit` is entered per day and spent as a pool: a group at 10 a day
     * may print 310 on the first of a 31-day month and nothing after. Owner's
     * decision, 2026-07-31 — the alternative (a hard per-day ceiling) would
     * refuse a legitimate month's run handed in at once, which is how this
     * department actually receives work.
     *
     * Calendar days of the **Kyiv** month, matching the department limit, which
     * resets on the 1st. A UTC month would hand three hours of it to the
     * previous one — see KyivClock.
     *
     * Null means no limit: that is what an empty field and a zero both mean on
     * the admin form, and the form says so.
     */
    public function monthlyLimit(?\Carbon\Carbon $when = null): ?int
    {
        if ($this->daily_limit === null || $this->daily_limit <= 0) {
            return null;
        }

        return $this->daily_limit * \App\Support\KyivClock::daysInMonth($when);
    }
}
