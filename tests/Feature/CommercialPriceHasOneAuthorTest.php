<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Only the server prices a commercial order — audit R32-1 and R32-2.
 *
 * Round 30 found one rule written twice and diverged (the Riso tier ladder).
 * This is the same class, one layer up and about the number an operator reads
 * out loud: two constructors stated a **commercial** price they had no way to
 * compute.
 *
 *  - `RisoCalculator` sent the cost as the commercial figure. The server
 *    multiplies cost by `riso_commercial_markup` — 2,0 by default — so the
 *    screen showed half;
 *  - `BrochureConstructor` sent zero. The server applies a 2× markup, so the
 *    screen showed nothing at all;
 *  - and the cart summed both with `?? 0`, which makes «no price» and «priced
 *    at nothing» look identical.
 *
 * **Neither multiplier is sent to the page.** That is the fact that settles
 * it: the client could not have been right, so it had to stop claiming.
 *
 * The order itself was never mispriced — `OrderItemBuilder` trusts a snapshot
 * from the request only when it is byte-identical to one already stored, and
 * rebuilds anything else from authoritative data. What was wrong is the quote.
 *
 * **Measured on production 2026-08-04 and found latent, twice over:**
 * «Брошури» and «Тиражування» are both internal-only on the categories page,
 * and the commercial report has never contained either — every commercial
 * order in it is «Чорно-білий друк» or «Сканування». One checkbox would
 * change that, which is exactly the shape of R30-1.
 *
 * This file pins both halves: the server's rule, and the client's silence.
 */
class CommercialPriceHasOneAuthorTest extends TestCase
{
    /**
     * The two components must not state a commercial price.
     *
     * A source assertion, deliberately — the same reasoning as
     * `ReconciliationPageWiringTest`: what went wrong was not a formula but a
     * claim, and the claim lives in the payload these components push into the
     * cart. `null` there means «the server will price this»; a number means
     * somebody has re-invented a multiplier the page is not given.
     */
    public function test_the_constructors_that_cannot_price_commercially_do_not_pretend_to(): void
    {
        $components = [
            'Components/Riso/RisoCalculator.vue',
            'Components/Brochure/BrochureConstructor.vue',
        ];

        foreach ($components as $relative) {
            $source = $this->sourceOf($relative);

            foreach (['unit_price_commercial', 'total_price_commercial'] as $key) {
                $this->assertMatchesRegularExpression(
                    '/'.preg_quote($key, '/').':\s*null\b/',
                    $source,
                    "{$relative} states a {$key} it cannot compute: the page is sent no markup. "
                    .'Send null and let the server price it — see R32-1.',
                );
            }
        }
    }

    /**
     * The cart must not add an unknown price as zero.
     *
     * `?? 0` is what made the defect quiet: a brochure worth 300 ₴ and a
     * brochure with no price yet contributed the same nothing to the total an
     * operator reads out.
     */
    public function test_the_cart_pages_use_the_shared_total_rather_than_a_silent_zero(): void
    {
        foreach (['Pages/Orders/Create.vue', 'Pages/Orders/Edit.vue'] as $relative) {
            $source = $this->sourceOf($relative);

            $this->assertStringContainsString(
                'commercialTotal(cart.value)',
                $source,
                "{$relative} must total the cart through the shared rule (useCartTotals).",
            );

            $this->assertDoesNotMatchRegularExpression(
                '/total_price_commercial\s*\?\?\s*0/',
                $source,
                "{$relative} still counts «no price» as zero — that is R32-2.",
            );
        }
    }

    private function sourceOf(string $relative): string
    {
        $path = resource_path('js/'.$relative);

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
