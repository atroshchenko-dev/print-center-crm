<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AuditLog Model
 *
 * APPEND-ONLY system audit trail.
 * Records security/business events: cancellations, defects, withdrawals,
 * counter adjustments, limit breaches, shift auto-closures, etc.
 *
 * ⚠️ Same immutability principle as LedgerTransaction.
 *
 * @property int $id
 * @property string $event_type
 * @property int $user_id
 * @property int|null $shift_id
 * @property string $description
 * @property array|null $meta
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'user_id',
        'shift_id',
        'description',
        'meta',
    ];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    // ─── Immutability Guards ─────────────────────────────

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \RuntimeException('AuditLog records are IMMUTABLE. UPDATE is forbidden.');
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): never
    {
        throw new \RuntimeException('AuditLog records are IMMUTABLE. UPDATE is forbidden.');
    }

    public function delete(): never
    {
        throw new \RuntimeException('AuditLog records are IMMUTABLE. DELETE is forbidden.');
    }

    public function forceDelete(): never
    {
        throw new \RuntimeException('AuditLog records are IMMUTABLE. DELETE is forbidden.');
    }

    // ─── Relationships ───────────────────────────────────

    /**
     * `withTrashed()`, and it is the point of the four guards above.
     *
     * The row cannot be edited or deleted — and deactivating the person named
     * in it rewrote every one of them anyway: the global scope drops the user,
     * `$log->user` becomes null, the page shows an empty cell and the workbook
     * prints «Система». An immutable record that says the system did what a
     * person did is worse than a missing one; it is a wrong answer with the
     * authority of a journal.
     *
     * A journal names who acted. Whether that person still works here is a
     * different question, asked on a different page.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    // ─── Factory Method ──────────────────────────────────

    public static function record(
        string $eventType,
        ?User $user,
        string $description,
        ?int $shiftId = null,
        array $meta = [],
    ): self {
        return self::create([
            'event_type'  => $eventType,
            'user_id'     => $user?->id,
            'shift_id'    => $shiftId,
            'description' => $description,
            'meta'        => empty($meta) ? null : $meta,
        ]);
    }
}
