<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CascadesRenameToOrders;
use App\Models\Concerns\InvalidatesReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

/**
 * UniversityRef Model — Authorized signatories for internal orders.
 *
 * Each signatory belongs to a SignatoryGroup which defines
 * which service categories they can access and daily limits.
 *
 * @property string|null $email
 * @property string $full_name
 * @property int|null $signatory_group_id
 * @property-read SignatoryGroup|null $group
 * @property-read Collection<int, SignatoryCostCenter> $costCenters
 */
class UniversityRef extends Model
{
    use CascadesRenameToOrders;
    use HasFactory;
    use InvalidatesReferenceCache;
    use Notifiable;
    use SoftDeletes;

    protected static string $referenceCacheKey = 'ref:signatories';

    protected static string $renameSourceColumn = 'full_name';

    protected static string $renameOrderColumn = 'authorized_person';

    protected $fillable = [
        'full_name',
        'position',
        'email',
        'is_active',
        'signatory_group_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ─── Relationships ───────────────────────────────────

    public function group(): BelongsTo
    {
        return $this->belongsTo(SignatoryGroup::class, 'signatory_group_id');
    }

    public function costCenters(): HasMany
    {
        return $this->hasMany(SignatoryCostCenter::class, 'university_ref_id');
    }

    // ─── Accessors ───────────────────────────────────────
    //
    // `getDailyLimit()` and `getCategories()` used to sit here. Round 15 gave
    // the group quota a reader without going through either, so by round 17
    // neither had a single call site anywhere — checked 2026-07-31, `grep` over
    // `app`, `resources`, `tests` and `database`.
    //
    // They are gone rather than left as a convenience, because the first of them
    // was a wrong answer waiting to be used: it returned `daily_limit` raw,
    // while the rule that is actually enforced spends that figure as a monthly
    // pool (`SignatoryGroup::monthlyLimit()` — daily × days of the Kyiv month).
    // Two ways to ask one question, differing by a factor of about thirty-one,
    // is exactly the shape that produced R16-2 and the fix above it.
}
