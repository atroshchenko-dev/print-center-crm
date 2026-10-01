<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\InvalidatesReferenceCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Riso Price Tier
 *
 * Tiered pricing for risograph duplication.
 * Each tier defines a cost per copy (A3 pass) for a given quantity range.
 *
 * @property int $id
 * @property int $min_qty
 * @property int|null $max_qty null = unlimited (501+)
 * @property float $cost_per_copy
 */
class RisoPriceTier extends Model
{
    use HasFactory;
    use InvalidatesReferenceCache;
    use SoftDeletes;

    protected static string $referenceCacheKey = 'ref:riso_tiers';

    protected $fillable = [
        'min_qty',
        'max_qty',
        'cost_per_copy',
    ];

    protected $casts = [
        'min_qty'       => 'integer',
        'max_qty'       => 'integer',
        'cost_per_copy' => 'decimal:4',
    ];

    /**
     * Find the tier for a given number of A3 sheets.
     *
     * Two cases used to share one fallback, and only one of them was meant to
     *. The comment said «if below minimum tier» and the code did
     * that **and** something else: it also caught a hole in the middle of the
     * ladder and answered with the cheapest-numbered — that is, the dearest —
     * tier, silently.
     *
     * Measured rather than argued: on the seeded ladder, deleting the 200–249
     * tier moved a 220-sheet run from 0,1400 to 0,4100 per copy — 30,80 ₴ of
     * printing became 90,20 ₴, and `tier_label` cheerfully said «50–99».
     * One click of «видалити» on the Різографія page does it.
     *
     * Below the lowest tier is a real, intended case: the ladder starts at 50
     * sheets and a run of ten still has to be priced. A hole is not — nobody
     * decided what a 220-sheet run costs when the tier for it was removed, and
     * inventing an answer is worse than refusing one. So this returns null and
     * `RisoPricingService::calculate()` throws the message it has always had.
     */
    public static function findForQuantity(int $sheetsA3): ?self
    {
        $tier = static::where('min_qty', '<=', $sheetsA3)
            ->where(fn ($q) => $q->whereNull('max_qty')
                ->orWhere('max_qty', '>=', $sheetsA3))
            ->orderByDesc('min_qty')
            ->first();

        if ($tier) {
            return $tier;
        }

        $lowest = static::orderBy('min_qty')->first();

        // Below the ladder — intended. Inside it, with nothing matching, the
        // ladder is broken and this must not pretend otherwise.
        return $lowest && $sheetsA3 < $lowest->min_qty ? $lowest : null;
    }

    /**
     * Holes in the ladder, as `[from, to]` pairs of A3-sheet counts.
     *
     * Nothing stops an admin leaving one: the page edits `min_qty`/`max_qty`
     * inline and deletes tiers outright, and no rule looks at the ladder as a
     * whole. Rather than forbid the edit — the natural way to rebuild a ladder
     * is to break it briefly — the gaps are shown on the page that makes them.
     *
     * `to => null` is the open top: a ladder whose last tier ends at 499 and
     * has no «500+» row cannot price a run of 600 either, and that hole is the
     * easiest of all to leave — one row deleted.
     *
     * @return array<int, array{from: int, to: int|null}>
     */
    public static function gaps(): array
    {
        $tiers = static::orderBy('min_qty')->get(['min_qty', 'max_qty']);

        if ($tiers->isEmpty()) {
            return [];
        }

        $gaps = [];
        $previousMax = null;

        foreach ($tiers as $tier) {
            if ($previousMax !== null && $tier->min_qty > $previousMax + 1) {
                $gaps[] = ['from' => $previousMax + 1, 'to' => $tier->min_qty - 1];
            }

            // An open-ended tier swallows everything above it: once one is
            // reached, later rows cannot leave a hole behind them.
            if ($tier->max_qty === null) {
                return $gaps;
            }

            $previousMax = max($previousMax ?? 0, $tier->max_qty);
        }

        $gaps[] = ['from' => $previousMax + 1, 'to' => null];

        return $gaps;
    }
}
