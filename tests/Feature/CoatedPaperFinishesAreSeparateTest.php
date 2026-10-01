<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\ServiceParameterOption;
use Database\Seeders\BusinessCardConstructorSeeder;
use Database\Seeders\InventoryCategorySeeder;
use Database\Seeders\InventoryItemSeeder;
use Database\Seeders\PrintConstructorSeeder;
use Database\Seeders\ServiceCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gloss and matte coated paper are counted separately on the shelf, so they
 * are separate inventory items — and the A3→A4 cutting rule must stay inside
 * one finish. A ratio pointing from matte A4 at gloss A3 would let the
 * constructor cut the wrong pile the moment matte ran out.
 */
class CoatedPaperFinishesAreSeparateTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const FORMATS = ['А3', 'А4'];

    /** @var list<int> */
    private const WEIGHTS = [150, 250, 300];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(InventoryCategorySeeder::class);
        $this->seed(InventoryItemSeeder::class);
    }

    public function test_every_coated_weight_exists_in_both_finishes(): void
    {
        foreach (self::FORMATS as $format) {
            foreach (self::WEIGHTS as $weight) {
                foreach (['глянець', 'мат'] as $finish) {
                    $name = "Папір {$format} {$weight} г/м² (крейдований, {$finish})";

                    $this->assertTrue(
                        InventoryItem::where('name', $name)->exists(),
                        "Missing coated paper item: {$name}"
                    );
                }
            }
        }
    }

    public function test_no_coated_item_is_left_without_a_finish(): void
    {
        $ambiguous = InventoryItem::where('name', 'like', '%(крейдований)%')->pluck('name');

        $this->assertEmpty(
            $ambiguous->all(),
            'Coated paper without a finish is unsellable ambiguity: '.$ambiguous->implode(', ')
        );
    }

    public function test_a4_cutting_never_crosses_the_finish(): void
    {
        foreach (self::WEIGHTS as $weight) {
            foreach (['глянець', 'мат'] as $finish) {
                $a4 = InventoryItem::where('name', "Папір А4 {$weight} г/м² (крейдований, {$finish})")
                    ->firstOrFail();

                $this->assertNotNull(
                    $a4->convertible_from_id,
                    "А4 {$weight} ({$finish}) has no A3 source to cut from"
                );

                $this->assertSame(
                    "Папір А3 {$weight} г/м² (крейдований, {$finish})",
                    $a4->convertibleFrom->name,
                    "А4 {$weight} ({$finish}) cuts from the wrong finish"
                );

                $this->assertSame('2.00', $a4->conversion_ratio);
            }
        }
    }

    /**
     * Stock nobody can pick is stock nobody can sell. Matte exists on the shelf
     * only because the constructors offer it, so each matte item must be
     * reachable through exactly as many options as its gloss twin — the twin is
     * the yardstick because it already carries every service that sells this
     * weight (business cards, for one, sell only A4 250 and 300).
     */
    public function test_matte_is_offered_wherever_gloss_is(): void
    {
        $this->seed(ServiceCategorySeeder::class);
        $this->seed(PrintConstructorSeeder::class);
        $this->seed(BusinessCardConstructorSeeder::class);

        foreach (self::FORMATS as $format) {
            foreach (self::WEIGHTS as $weight) {
                $gloss = $this->optionsBoundTo("Папір {$format} {$weight} г/м² (крейдований, глянець)");
                $matte = $this->optionsBoundTo("Папір {$format} {$weight} г/м² (крейдований, мат)");

                $this->assertGreaterThan(
                    0,
                    $gloss,
                    "Gloss {$format} {$weight} is bound to no option — the yardstick itself is broken"
                );

                $this->assertSame(
                    $gloss,
                    $matte,
                    "Matte {$format} {$weight} is offered by {$matte} option(s) against gloss's {$gloss}"
                );
            }
        }
    }

    private function optionsBoundTo(string $inventoryName): int
    {
        $id = InventoryItem::where('name', $inventoryName)->value('id');

        return ServiceParameterOption::where('inventory_item_id', $id)->count();
    }
}
