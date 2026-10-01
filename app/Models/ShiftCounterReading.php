<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ShiftCounterReading Model
 *
 * Append-only record of counter readings per equipment per shift.
 * No UPDATE, no DELETE — readings are permanent.
 *
 * Validation rule (TZ §4.1): a new reading must be >= the last known one for
 * the same equipment — see lastKnownValue() and BASELINE_TYPES below.
 *
 * On `evening`: the column accepts it, the CHECK constraint lists it and the
 * unique index is keyed on `reading_type IN ('morning','evening')` — and
 * nothing has ever written one. The design was simplified to a morning-only
 * reading (`ShiftCloseService`: "no evening readings or cash reconciliation
 * needed"), and `BASELINE_TYPES` leaves it out deliberately. It is a vestige,
 * not a feature that stopped working: nothing offers it to anyone, so nothing
 * misleads anyone. It stays because dropping it means altering the CHECK and
 * rebuilding the partial unique index on a live table, for no visible gain.
 * Audited in round 15 and left alone on purpose — do not re-open it as a lead.
 *
 * @property int $id
 * @property int $shift_id
 * @property int $equipment_id
 * @property int $user_id
 * @property string $reading_type 'morning' | 'adjustment' — also accepts the vestigial 'evening'
 * @property int $counter_value
 * @property Carbon $created_at
 */
class ShiftCounterReading extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'shift_id',
        'equipment_id',
        'user_id',
        'reading_type',
        'counter_value',
    ];

    protected $casts = [
        'counter_value' => 'integer',
        'created_at'    => 'datetime',
    ];

    // ─── Immutability Guards ─────────────────────────────

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \RuntimeException(
                'ShiftCounterReading records are IMMUTABLE. '.
                'UPDATE via save() is forbidden.'
            );
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): never
    {
        throw new \RuntimeException('ShiftCounterReading records are IMMUTABLE. UPDATE is forbidden.');
    }

    public function delete(): never
    {
        throw new \RuntimeException('ShiftCounterReading records are IMMUTABLE. DELETE is forbidden.');
    }

    public function forceDelete(): never
    {
        throw new \RuntimeException('ShiftCounterReading records are IMMUTABLE. DELETE is forbidden.');
    }

    // ─── Relationships ───────────────────────────────────

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * `withTrashed()` on both, for the reason the readings are immutable at all.
     *
     * Deactivating a machine is soft — the delete button on the equipment page
     * says so in as many words, «counter readings reference equipment for the
     * whole audit trail». The relation then applied the global scope anyway, so
     * every reading the machine ever gave read «—» in the report and in the
     * export the moment it was retired: the row survived, the name it was about
     * did not. Same for the operator who took the reading.
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    // ─── Helpers ─────────────────────────────────────────

    /**
     * Reading types that say what the machine currently shows.
     *
     * An adjustment is the whole point of TZ §3.4: the physical counter no
     * longer reads what it used to — a replaced board, a service reset — and
     * an admin records the new figure. Read against morning readings alone,
     * the adjustment was written, audited, and then obeyed by nothing: after a
     * reset from 100 000 to zero the next shift could not be opened, because
     * the true reading of 500 was refused as lower than "the previous one".
     */
    public const BASELINE_TYPES = ['morning', 'adjustment'];

    /**
     * The last counter value recorded for this equipment, whatever set it.
     *
     * One definition: the open form pre-fills from it and openShift() refuses
     * anything below it, and a form that offers a value its own validation
     * rejects is worse than no pre-fill at all. `id` breaks the tie because
     * `created_at` has second resolution and two readings can share one.
     */
    public static function lastKnownValue(int $equipmentId): ?int
    {
        $value = static::where('equipment_id', $equipmentId)
            ->whereIn('reading_type', self::BASELINE_TYPES)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('counter_value');

        return $value === null ? null : (int) $value;
    }

    public function isMorning(): bool
    {
        return $this->reading_type === 'morning';
    }

    public function isEvening(): bool
    {
        return $this->reading_type === 'evening';
    }
}
