<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Material Model — Printer amortization cost per click.
 *
 * @property string $counter_type bw | color | riso
 * @property float $click_cost Current cost per click (auto-fills option cost_markup)
 */
class Material extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'counter_type',
        'click_cost',
        'pending_click_cost',
        'pending_activated_at',
        'is_active',
    ];

    protected $casts = [
        'click_cost'           => 'decimal:4',
        'pending_click_cost'   => 'decimal:4',
        'pending_activated_at' => 'datetime',
        'is_active'            => 'boolean',
    ];

    /**
     * After click_cost changes → refresh cost_markup on all linked Constructor options.
     */
    protected static function booted(): void
    {
        static::updated(function (self $material): void {
            if ($material->wasChanged('click_cost')) {
                DB::transaction(function () use ($material) {
                    ServiceParameterOption::where('counter_type', $material->counter_type)
                        ->whereNotNull('clicks_per_unit')
                        ->cursor()
                        ->each(fn ($opt) => $opt->save()); // triggers saving hook → recomputes cost
                });
            }
        });
    }

    public function hasPendingPrice(): bool
    {
        return $this->pending_click_cost !== null && $this->pending_activated_at !== null;
    }

    /**
     * What a click costs, by counter type.
     *
     * Three copies of this stood in three files —
     * `ReferenceDataService::clickCosts()` (which feeds the live price on both
     * order forms), `BrochurePricingService::getClickCosts()` and
     * `DiplomaPricingService::getClickCosts()` — identical down to the 0.12 and
     * 0.60 they fall back to. They had not drifted yet; a second copy is simply
     * how two rules come to (M-9, R3-1, R3-18, and the three Vue rule modules
     * round 16 pulled out after the copies had already disagreed).
     *
     * `orderBy('id')` is the behaviour the three copies already had in practice
     * and did not state. Round 20 made a second active row impossible
     * (`unique_active_material_per_counter_type`, owner's decision, CLOSEOUT
     * §1.8), so the ordering is belt-and-braces now rather than a tie-break —
     * kept because it describes the same behaviour either way.
     *
     * **When nothing is active, the last known price wins** — owner's decision
     * of 2026-08-01 (CLOSEOUT §1.8-bis). The constants below are 0.12 and 0.60;
     * production charges 0.3700 and 2.0400. Falling back to them is therefore
     * not "safe by default", it is a silent 3.1× and 3.4× discount — R19-2 with
     * a plausible number in place of a zero, which is harder to spot rather than
     * easier. A deactivated material still remembers what a click cost; a
     * constant remembers nothing. Same choice R19-1 made about the deleted
     * department.
     *
     * The constants survive for the one case where nothing at all is known — an
     * empty database and the tests — because there any number is invented, and
     * these two at least have a history.
     *
     * @return array{bw: float, color: float}
     */
    public static function clickCosts(): array
    {
        return once(fn (): array => [
            'bw'    => self::costPerClick('bw', 0.12),
            'color' => self::costPerClick('color', 0.60),
        ]);
    }

    private static function costPerClick(string $counterType, float $fallback): float
    {
        $cost = static::where('is_active', true)
            ->where('counter_type', $counterType)
            ->orderBy('id')
            ->value('click_cost');

        // Nothing active: the newest deactivated row is the last price this
        // counter type is known to have had. Trashed rows count — a material
        // removed from the list is still what the printing cost back then.
        $cost ??= static::withTrashed()
            ->where('counter_type', $counterType)
            ->orderByDesc('id')
            ->value('click_cost');

        return $cost === null ? $fallback : (float) $cost;
    }
}
