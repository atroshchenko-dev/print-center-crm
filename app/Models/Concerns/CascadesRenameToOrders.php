<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Carry a reference row's new name over to the orders that name it.
 *
 * `orders.authorized_person` and `orders.cost_center` hold **strings**, not
 * foreign keys — a denormalisation the whole project is built on, because an
 * order has to keep saying who signed for it even after the person leaves. What
 * it costs is that every rule spending a quota looks the reference up by that
 * string:
 *
 *   - `LimitService::incrementUsage()` / `returnUsage()` find the `Department`
 *     by name, and quietly return when they do not;
 *   - `LimitService::groupUsageThisMonth()` sums the pool by matching
 *     `authorized_person` against the current names of the group's signatories.
 *
 * So correcting a surname or a department's title — an ordinary edit on the
 * university page — used to cut every order taken before that moment loose from
 * its limit. The department counter then missed the spend entirely when the
 * order was handed over, which is the more expensive half: a missed increment
 * is a quota spent with nothing recorded.
 *
 * Done on the model rather than in `UniversityController`, for the same reason
 * `InvalidatesReferenceCache` is: a new writer — another controller, a console
 * command, a seeder, someone in tinker — cannot forget.
 *
 * `updated_at` on the orders is deliberately left where it was. This is not an
 * edit of the order and must not read as one on the card or in the journal.
 *
 * The model declares the two columns:
 *
 *     protected static string $renameSourceColumn = 'full_name';
 *     protected static string $renameOrderColumn  = 'authorized_person';
 */
trait CascadesRenameToOrders
{
    protected static function bootCascadesRenameToOrders(): void
    {
        static::updated(static function ($model): void {
            $model->cascadeRenameToOrders();
        });
    }

    /** @return int how many orders were carried over */
    public function cascadeRenameToOrders(): int
    {
        $source = static::$renameSourceColumn;
        $target = static::$renameOrderColumn;

        if (! $this->wasChanged($source)) {
            return 0;
        }

        $previous = $this->getOriginal($source);
        $current = $this->{$source};

        if (empty($previous) || $previous === $current) {
            return 0;
        }

        // Soft-deleted orders included: a deleted order still carries the name
        // for the audit trail, and a restore must not bring the old one back.
        return Order::withTrashed()
            ->where($target, $previous)
            ->update([
                $target => $current,
                'updated_at' => DB::raw('updated_at'),
            ]);
    }
}
