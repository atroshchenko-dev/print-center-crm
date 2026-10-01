<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\RisoPriceTier;
use App\Models\User;
use App\Services\RisoPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ladder as a whole.
 *
 * `findForQuantity()` had one fallback serving two cases, and its comment
 * described only the intended one: «if below minimum tier, use the lowest
 * available tier». The other case was a hole in the middle of the ladder, and
 * there the same line answered with the lowest-numbered — that is, the
 * dearest — tier. Silently, and on money.
 *
 * Measured before it was fixed, on the seeded ladder: deleting the 200–249
 * tier moved a 220-sheet run from 0,1400 to 0,4100 per copy — 30,80 ₴ of
 * printing became 90,20 ₴. `tier_label` said «50–99» for a run of 220, which
 * is the only place the screen betrayed it.
 *
 * Reachable by one click of «видалити» on the Різографія page, and by an
 * ordinary edit of `max_qty` there.
 */
class RisoTierLadderTest extends TestCase
{
    use RefreshDatabase;

    /** The production ladder, as `RisoPriceTierSeeder` writes it. */
    private function seedLadder(): void
    {
        RisoPriceTier::query()->forceDelete();

        foreach ([
            [50, 99, 0.41], [100, 149, 0.23], [150, 199, 0.17], [200, 249, 0.14],
            [250, 299, 0.12], [300, 349, 0.11], [350, 399, 0.10], [400, 449, 0.10],
            [450, 499, 0.09], [500, null, 0.09],
        ] as [$min, $max, $cost]) {
            RisoPriceTier::create(['min_qty' => $min, 'max_qty' => $max, 'cost_per_copy' => $cost]);
        }
    }

    // ─── The refusal ─────────────────────────────────────

    public function test_a_run_that_falls_into_a_hole_is_refused_not_priced(): void
    {
        $this->seedLadder();
        RisoPriceTier::where('min_qty', 200)->first()->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('220');

        (new RisoPricingService)->calculate(copies: 220, format: 'A3', sides: 1);
    }

    /**
     * The number this test exists to prevent, written down.
     *
     * Before R30-1 the call above returned quietly, at 0,4100 per copy against
     * the 0,1400 the ladder was built to charge.
     */
    public function test_the_price_the_hole_used_to_produce(): void
    {
        $this->seedLadder();
        config(['riso.paper_cost_a3' => 0.0]);

        $whole = (new RisoPricingService)->calculate(copies: 220, format: 'A3', sides: 1);

        $this->assertSame(0.14, $whole['cost_per_copy']);
        $this->assertSame(30.80, $whole['print_cost']);
        $this->assertSame('200–249', $whole['tier_label']);
    }

    /**
     * Below the ladder stays as it was, and this is the half that was meant.
     *
     * The ladder starts at 50 sheets; a run of ten still has to be priced, and
     * the lowest tier is the answer somebody chose. Breaking this while fixing
     * the hole would have been the easy mistake.
     */
    public function test_a_run_below_the_lowest_tier_still_uses_the_lowest_tier(): void
    {
        $this->seedLadder();

        $result = (new RisoPricingService)->calculate(copies: 10, format: 'A3', sides: 1);

        $this->assertSame(0.41, $result['cost_per_copy']);
        $this->assertSame('50–99', $result['tier_label']);
    }

    public function test_an_intact_ladder_prices_every_step_from_its_own_tier(): void
    {
        $this->seedLadder();

        foreach ([[60, 0.41], [120, 0.23], [220, 0.14], [480, 0.09], [5000, 0.09]] as [$sheets, $expected]) {
            $this->assertSame(
                $expected,
                (new RisoPricingService)->calculate(copies: $sheets, format: 'A3', sides: 1)['cost_per_copy'],
                "A run of {$sheets} sheets took the wrong tier.",
            );
        }
    }

    /**
     * Deleting the open-ended tier leaves the top of the ladder open, and that
     * is the same defect from the other end: every large run used to fall back
     * to the dearest tier.
     */
    public function test_removing_the_open_ended_tier_refuses_large_runs(): void
    {
        $this->seedLadder();
        RisoPriceTier::where('min_qty', 500)->first()->delete();

        $this->expectException(\RuntimeException::class);

        (new RisoPricingService)->calculate(copies: 600, format: 'A3', sides: 1);
    }

    // ─── The detector shown on the page ──────────────────

    public function test_an_intact_ladder_has_no_gaps(): void
    {
        $this->seedLadder();

        $this->assertSame([], RisoPriceTier::gaps());
    }

    public function test_a_deleted_middle_tier_is_reported_as_a_gap(): void
    {
        $this->seedLadder();
        RisoPriceTier::where('min_qty', 200)->first()->delete();

        $this->assertSame([['from' => 200, 'to' => 249]], RisoPriceTier::gaps());
    }

    public function test_an_open_top_is_reported_as_a_gap_with_no_end(): void
    {
        $this->seedLadder();
        RisoPriceTier::where('min_qty', 500)->first()->delete();

        $this->assertSame([['from' => 500, 'to' => null]], RisoPriceTier::gaps());
    }

    /**
     * Two rows overlapping is not a gap, and it is not a defect either: the
     * lookup takes the highest `min_qty` that fits, so the answer is defined.
     * Said here so the next round does not reopen it as one.
     */
    public function test_overlapping_tiers_are_not_a_gap_and_resolve_to_the_higher_step(): void
    {
        RisoPriceTier::query()->forceDelete();
        RisoPriceTier::create(['min_qty' => 1,   'max_qty' => 300,  'cost_per_copy' => 0.50]);
        RisoPriceTier::create(['min_qty' => 100, 'max_qty' => null, 'cost_per_copy' => 0.20]);

        $this->assertSame([], RisoPriceTier::gaps());
        $this->assertSame(
            0.20,
            (new RisoPricingService)->calculate(copies: 150, format: 'A3', sides: 1)['cost_per_copy'],
        );
    }

    public function test_an_empty_ladder_reports_nothing_rather_than_one_infinite_gap(): void
    {
        RisoPriceTier::query()->forceDelete();

        $this->assertSame([], RisoPriceTier::gaps());
    }

    public function test_the_page_carries_the_gaps_it_makes(): void
    {
        $this->seedLadder();
        RisoPriceTier::where('min_qty', 200)->first()->delete();

        $admin = User::factory()->create([
            'role'        => 'admin',
            'permissions' => User::permissionKeys(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.riso-pricing.index'))
            ->assertInertia(fn ($page) => $page
                ->where('gaps', [['from' => 200, 'to' => 249]])
            );
    }
}
