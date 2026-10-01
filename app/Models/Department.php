<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CascadesRenameToOrders;
use App\Models\Concerns\InvalidatesReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Department Model — Cost centers (departments and projects).
 *
 * @property int $id
 * @property string $name
 * @property string $type 'department' | 'project'
 * @property bool $is_active
 * @property-read Collection<int, CostCenterInitiator> $initiators
 */
class Department extends Model
{
    use CascadesRenameToOrders;
    use HasFactory;
    use InvalidatesReferenceCache;
    use SoftDeletes;

    /** Created on the fly by Department::remember() while saving an order, too. */
    protected static string $referenceCacheKey = 'ref:departments';

    /**
     * A department row feeds a second cached list besides its own.
     *
     * `ReferenceDataService::signatoryCostCenters()` builds its map by joining
     * `departments` and reading `name`, `is_active` and `deleted_at` from it —
     * so a rename or a deactivation changes that map without touching a single
     * `signatory_cost_centers` row, and no model event on this table would ever
     * drop it.
     *
     * The stale map is not merely cosmetic: `CostCenterSelect` auto-fills a
     * signatory's only centre, so within the hour after an admin fixes a typo
     * («Департамерт реклами» → «Відділ реклами») the form would hand back the
     * old spelling, and `Department::remember()` — finding no row under that
     * name any more — would create the duplicate again.
     *
     * @return array<int, string>
     */
    protected static function referenceCacheKeys(): array
    {
        return [static::$referenceCacheKey, 'ref:signatory_cost_centers', 'ref:cost_center_initiators'];
    }

    protected static string $renameSourceColumn = 'name';

    protected static string $renameOrderColumn = 'cost_center';

    protected $fillable = [
        'name',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ─── Relationships ───────────────────────────────────

    public function limits(): HasMany
    {
        return $this->hasMany(DepartmentLimit::class);
    }

    public function initiators(): HasMany
    {
        return $this->hasMany(CostCenterInitiator::class);
    }

    // ─── Helpers ─────────────────────────────────────────

    public function isDepartment(): bool
    {
        return $this->type === 'department';
    }

    public function isProject(): bool
    {
        return $this->type === 'project';
    }

    /**
     * Get limit for a specific type (e.g., 'bw_copies').
     */
    public function getLimit(string $limitType): ?DepartmentLimit
    {
        return $this->limits()->where('limit_type', $limitType)->first();
    }

    /**
     * Keep the cost centre an order names in the reference book (ТЗ §3.1),
     * without forking the row it already has.
     *
     * Five callers wrote this as `firstOrCreate(['name' => …])`: both order
     * paths, both retro paths and the retro import. The global scope hides a
     * soft-deleted row from all five, so an order naming a deleted cost centre
     * created a **second** row reading the same thing — and since `name` carries
     * no unique index, nothing downstream can tell the two apart:
     * `where('name', …)->first()` picks whichever the database hands back, and
     * only one of the two owns the `DepartmentLimit`.
     *
     * A deleted row is therefore found rather than duplicated, and left deleted:
     * an order naming a cost centre is not a decision to bring it back into the
     * list. Living on the model for the same reason `CascadesRenameToOrders`
     * does — a sixth caller cannot forget. Those five call sites are gone now:
     * `OrderObserver` makes the call once, for every path that saves an order,
     * and it has to be the one making it — the signatory ↔ cost-centre pair the
     * same observer writes looks this row up, so it must already exist.
     *
     * **The match ignores case and surrounding spaces**, and it has to: round 20
     * put a unique index on `LOWER(TRIM(name))`, so an exact-match lookup would
     * miss «Кафедра ІМЗД» when handed «кафедра ІМЗД», try to insert, and take
     * the whole order down with a constraint violation — a reference-book rule
     * surfacing as a failed save on an unrelated screen. Production holds
     * exactly that pair.
     */
    public static function remember(?string $name): void
    {
        $name = trim((string) $name);

        if ($name === '') {
            return;
        }

        $existing = static::withTrashed()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->exists();

        if ($existing) {
            return;
        }

        static::create(['name' => $name, 'type' => 'department', 'is_active' => true]);
    }

    /*
     * There was a wouldExceedLimit() here. LimitService::checkLimit() is what
     * the order flow actually calls; this was a second copy of the same rule
     * that no caller had ever reached, and a second copy is how the two drift
     * apart (audit M-9, R3-1, R3-18 — all the same shape).
     */
}
