<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DepartmentLimit Model
 *
 * Tracks monthly print quotas per department.
 *
 * **No row of this table has ever existed outside a test.** The docblock used
 * to end «resets automatically on the 1st of each month via scheduler», and
 * round 4 checked the two halves it could see — the job exists, the job is
 * scheduled — and read the promise as kept. The third half is that nothing in
 * the product creates a row: the department form saves `name`, `type` and
 * `is_active`, and no seeder or migration writes here either. Production held
 * zero rows when it was finally counted, 2026-08-04.
 *
 * By the owner's decision of that day (CLOSEOUT §1.12) the cost-centre quota is
 * **not used**, the monthly reset is out of the schedule, and the one quota
 * this system applies is the signatory-group pool in `LimitService`.
 *
 * Kept, not dropped: the table, the model and the job together are what turning
 * the quota on would need, and the missing piece is a way to set the number.
 *
 * @property int $id
 * @property int $department_id
 * @property string $limit_type e.g. 'bw_copies'
 * @property int $monthly_limit
 * @property int $current_usage
 * @property Carbon|null $reset_at
 */
class DepartmentLimit extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'department_id',
        'limit_type',
        'monthly_limit',
        'current_usage',
        'reset_at',
    ];

    protected $casts = [
        'monthly_limit' => 'integer',
        'current_usage' => 'integer',
        'reset_at'      => 'datetime',
    ];

    // ─── Relationships ───────────────────────────────────

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    // ─── Helpers ─────────────────────────────────────────

    public function getRemainingAttribute(): int
    {
        return max(0, $this->monthly_limit - $this->current_usage);
    }

    /*
     * isExceeded() and incrementUsage() lived here and were called from
     * nowhere. Both had a live counterpart in LimitService that disagreed with
     * them: checkLimit() asks whether the request *would* go over, not whether
     * usage is already past the limit, and it increments under lockForUpdate,
     * which the model method did not. Quotas are decided in one place.
     */
}
