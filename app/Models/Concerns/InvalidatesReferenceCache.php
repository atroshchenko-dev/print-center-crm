<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Forget the reference-data cache entry this model feeds, whenever a row
 * changes.
 *
 * ReferenceDataService caches five lists for an hour. Invalidation used to be
 * the caller's job, and three of the callers did it: ServiceController,
 * ServiceCategoryController and RisoPricingController. The ones editing
 * parameter groups, parameter options, signatories and departments did not —
 * and options are where constructor prices actually live. An admin changed a
 * price and the order screen kept the old option list for up to an hour.
 *
 * Doing it here instead of at the call site means a new writer — a controller,
 * a console command, a seeder, someone in tinker — cannot forget.
 *
 * The model declares which key it feeds:
 *
 *     protected static string $referenceCacheKey = 'ref:services';
 *
 * One table can feed more than one list — `departments` feeds both
 * `ref:departments` and the `ref:signatory_cost_centers` map, which is built by
 * joining it. A model in that position overrides `referenceCacheKeys()` rather
 * than the property, so the single-key declaration above keeps meaning exactly
 * what it says for every other model using this trait.
 */
trait InvalidatesReferenceCache
{
    protected static function bootInvalidatesReferenceCache(): void
    {
        // `saved` covers create and update; `deleted` covers both soft and
        // hard deletes; `restored` matters because a restored row belongs
        // back in the list.
        //
        // Deferred to the commit, not fired on the event: remember() writes
        // run inside the order's transaction, and a forget issued before
        // commit opens a window in which a parallel reader re-caches the
        // pre-commit state for the full TTL. Outside a transaction
        // afterCommit runs the callback immediately.
        //
        // Deferred on the model's own connection, not the default one:
        // `DB::afterCommit()` resolves `database.default`, which is
        // accidentally correct today only because the app writes through a
        // single connection — the model itself is not necessarily on that
        // connection in general, so asking it directly keeps this right
        // regardless of how many connections exist.
        foreach (['saved', 'deleted', 'restored'] as $event) {
            static::registerModelEvent($event, static function (Model $model): void {
                $model->getConnection()->afterCommit(static fn () => static::forgetReferenceCache());
            });
        }
    }

    public static function forgetReferenceCache(): void
    {
        foreach (static::referenceCacheKeys() as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Every cached list this model's rows feed. Defaults to the one it declares.
     *
     * @return array<int, string>
     */
    protected static function referenceCacheKeys(): array
    {
        return [static::$referenceCacheKey];
    }
}
