<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\InvalidatesReferenceCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SignatoryCostCenter — які центри витрат замовляє конкретний підписант.
 *
 * Пари не заводяться руками, а накопичуються з фактичних замовлень: у CRM
 * не було жодного зв'язку між підписантом і підрозділом, тому оператор гортав
 * увесь довідник (близько півсотні позицій) заради однієї-двох релевантних.
 *
 * `orders_count = 0` означає рядок, доданий адміністратором наперед —
 * замовлень із такою парою ще не було.
 *
 * @property int $university_ref_id
 * @property int $department_id
 * @property int $orders_count
 * @property Carbon|null $last_used_at
 * @property-read UniversityRef|null $signatory
 * @property-read Department|null $department
 */
class SignatoryCostCenter extends Model
{
    use HasFactory;
    use InvalidatesReferenceCache;

    /**
     * The map the order form narrows its cost-centre list with.
     *
     * The admin endpoints flushed it by hand; the observer that writes these
     * rows from a saved order did not — so the first order pairing a signatory
     * with a centre wrote the row and the next order still saw the full book
     * for up to an hour. Worse, the same save flushed `ref:departments` through
     * `Department::remember()`, so half of one save's effects were visible and
     * half were not.
     */
    protected static string $referenceCacheKey = 'ref:signatory_cost_centers';

    protected $fillable = [
        'university_ref_id',
        'department_id',
        'orders_count',
        'last_used_at',
    ];

    protected $casts = [
        'orders_count' => 'integer',
        'last_used_at' => 'datetime',
    ];

    // ─── Relationships ───────────────────────────────────

    public function signatory(): BelongsTo
    {
        return $this->belongsTo(UniversityRef::class, 'university_ref_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    // ─── Accumulation ────────────────────────────────────

    /**
     * Записати пару, названу замовленням.
     *
     * `orders.authorized_person` і `orders.cost_center` — рядки, не FK, тож
     * рядок довідника шукається нормалізовано, рівно як у
     * `Department::remember()`. Точний збіг розколов би «Кафедра ІМЗД»
     * і «кафедра ІМЗД» на дві пари — у проді така пара існує.
     *
     * Підписанта чи центру немає в довіднику (не заведений, деактивований) —
     * пара не пишеться і **нічого не кидається**: цей метод викликається
     * з-під збереження замовлення, і довідник не має права те збереження
     * завалити.
     */
    public static function remember(?string $signatoryName, ?string $costCenterName, ?\DateTimeInterface $usedAt = null): void
    {
        $signatoryName = trim((string) $signatoryName);
        $costCenterName = trim((string) $costCenterName);

        if ($signatoryName === '' || $costCenterName === '') {
            return;
        }

        $signatory = UniversityRef::whereRaw('LOWER(TRIM(full_name)) = ?', [mb_strtolower($signatoryName)])->first();
        $department = Department::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($costCenterName)])->first();

        if (! $signatory || ! $department) {
            return;
        }

        $usedAt = $usedAt ?? now();

        $pair = static::firstOrNew([
            'university_ref_id' => $signatory->id,
            'department_id'     => $department->id,
        ]);

        self::countOneUse($pair, $usedAt);

        // `firstOrNew` + `save` is read-then-write, and `(university_ref_id,
        // department_id)` carries a unique index: two operators saving the
        // first-ever order for the same pair at the same moment both read
        // nothing and both insert. The loser gets a constraint violation —
        // thrown from inside the order's own transaction, which would roll the
        // whole order back and break this method's promise never to take a save
        // down with it.
        //
        // The nested transaction is a SAVEPOINT, and it is what makes catching
        // possible at all on PostgreSQL: without one the failed statement
        // leaves the outer transaction aborted, so swallowing the exception
        // would only move the failure to the next query.
        try {
            DB::transaction(static fn () => $pair->save());
        } catch (UniqueConstraintViolationException) {
            // The other writer's row is committed and visible by now, so the
            // use this call represents is added to it instead of being lost.
            $winner = static::where('university_ref_id', $signatory->id)
                ->where('department_id', $department->id)
                ->first();

            if ($winner) {
                self::countOneUse($winner, $usedAt);
                $winner->save();
            }
        }
    }

    /**
     * One more order on this pair.
     *
     * `last_used_at` moves forward only. The backfill walks history in no
     * chronological order, and editing an old order brings an old date back —
     * «востаннє» has to stay the latest date seen, not the last one written.
     */
    private static function countOneUse(self $pair, \DateTimeInterface $usedAt): void
    {
        // `remember()` takes any `DateTimeInterface` — the caller may hand it
        // a plain `DateTime`, not necessarily a `Carbon` — but the property
        // itself is cast to `Carbon|null`, so the value written here has to
        // actually be one, not merely something that implements the same
        // interface.
        $usedAt = $usedAt instanceof Carbon ? $usedAt : Carbon::instance($usedAt);

        $pair->orders_count = ($pair->orders_count ?? 0) + 1;

        if ($pair->last_used_at === null || $pair->last_used_at->lt($usedAt)) {
            $pair->last_used_at = $usedAt;
        }
    }
}
