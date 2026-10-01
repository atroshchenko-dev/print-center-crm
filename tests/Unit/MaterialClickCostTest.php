<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Material;
use App\Services\ReferenceDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a click costs, asked once instead of three times.
 *
 * `ReferenceDataService::clickCosts()` feeds the live price on both order
 * forms; `BrochurePricingService` and `DiplomaPricingService` price the saved
 * order. All three carried their own copy of the same two queries and the same
 * two fallbacks, so the number the operator watched on the screen and the
 * number written into the order came from three places that merely happened to
 * agree.
 *
 * Nothing forbids a second active material of the same counter type — no unique
 * index, no check in `MaterialController` — and a bare `first()` on it returns
 * whichever row PostgreSQL feels like. Which of two should price a click is the
 * owner's question; that the answer must not change between two identical
 * requests is not.
 */
class MaterialClickCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_click_costs_come_from_the_active_materials(): void
    {
        Material::factory()->create(['counter_type' => 'bw', 'click_cost' => 0.15, 'is_active' => true]);
        Material::factory()->create(['counter_type' => 'color', 'click_cost' => 0.75, 'is_active' => true]);

        $this->assertSame(['bw' => 0.15, 'color' => 0.75], Material::clickCosts());
    }

    /**
     * **Changed by the owner's decision of 2026-08-01 (CLOSEOUT §1.8-bis.)**
     * This test used to assert the opposite — that a deactivated material falls
     * back to 0.12 — and that was the finding: the constants are 0.12 and 0.60
     * while production charges 0.3700 and 2.0400, so "falling back" was a silent
     * 3× discount wearing a plausible number. A deactivated row still remembers
     * what a click cost.
     */
    public function test_a_deactivated_material_still_says_what_a_click_cost(): void
    {
        Material::factory()->create(['counter_type' => 'bw', 'click_cost' => 0.15, 'is_active' => false]);

        $this->assertSame(0.15, Material::clickCosts()['bw']);
    }

    /** The invented number stands only where nothing at all is known. */
    public function test_with_no_row_at_all_the_constants_stand(): void
    {
        $this->assertSame(['bw' => 0.12, 'color' => 0.60], Material::clickCosts());
    }

    /**
     * **Also changed 2026-08-01, and for the stronger reason:** this used to
     * assert which of *two active* materials wins, because the schema allowed
     * two. It no longer does — `unique_active_material_per_counter_type` (owner's
     * decision, §1.8) makes the state unreachable, so the question the old test
     * answered cannot be asked.
     *
     * What remains worth stating is the pair that *is* reachable: one active row
     * and any number of retired ones. The active one prices the click, and the
     * retired one only speaks when nothing active is left.
     */
    public function test_an_active_material_outranks_a_retired_one_of_the_same_type(): void
    {
        $retired = Material::factory()->create(['counter_type' => 'bw', 'click_cost' => 0.99, 'is_active' => false]);
        $active = Material::factory()->create(['counter_type' => 'bw', 'click_cost' => 0.15, 'is_active' => true]);

        $this->assertLessThan($active->id, $retired->id, 'the retired row is the older one, so id order alone would pick it');
        $this->assertSame(0.15, Material::clickCosts()['bw']);
    }

    /**
     * The service the order forms read through is now the same rule, not a
     * fourth copy of it.
     */
    public function test_the_reference_service_reports_the_same_figures(): void
    {
        Material::factory()->create(['counter_type' => 'bw', 'click_cost' => 0.15, 'is_active' => true]);
        Material::factory()->create(['counter_type' => 'color', 'click_cost' => 0.75, 'is_active' => true]);

        $this->assertSame(
            Material::clickCosts(),
            app(ReferenceDataService::class)->clickCosts(),
        );
    }
}
