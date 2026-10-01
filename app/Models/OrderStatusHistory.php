<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OrderStatusHistory — append-only status transition log.
 *
 * Records every status change for the order timeline display.
 * Same immutability principle as AuditLog/LedgerTransaction.
 *
 * @property int $id
 * @property int $order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $user_id
 * @property string|null $comment
 * @property Carbon $created_at
 */
class OrderStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'order_status_history';

    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'user_id',
        'comment',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // ─── Immutability Guards ─────────────────────────────

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \RuntimeException('OrderStatusHistory records are IMMUTABLE.');
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): never
    {
        throw new \RuntimeException('OrderStatusHistory records are IMMUTABLE.');
    }

    public function delete(): never
    {
        throw new \RuntimeException('OrderStatusHistory records are IMMUTABLE.');
    }

    public function forceDelete(): never
    {
        throw new \RuntimeException('OrderStatusHistory records are IMMUTABLE.');
    }

    // ─── Relationships ───────────────────────────────────

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Who moved the status — see AuditLog::user() for why `withTrashed()`. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    // ─── Factory Method ──────────────────────────────────

    public static function record(
        Order $order,
        ?string $fromStatus,
        string $toStatus,
        ?User $user,
        ?string $comment = null,
    ): self {
        return self::create([
            'order_id'    => $order->id,
            'from_status' => $fromStatus,
            'to_status'   => $toStatus,
            'user_id'     => $user?->id,
            'comment'     => $comment,
        ]);
    }
}
