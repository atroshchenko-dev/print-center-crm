<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The published price is the price we charge.
 *
 * PriceListTest next door asserts HTTP 200 and nothing else, so it passes
 * whatever numbers the page happens to print. These assert the numbers.
 *
 * Four categories were served from literals typed into the controller —
 * transcribed from the seeders when the page was written, and never read
 * from the database since. Admins can edit those same prices at runtime
 * through the price-list module, and the page had no way of noticing.
 */
class PriceListAccuracyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build the soft-binding category the way the seeder does: a constructor
     * service whose "Пружина" options carry the commercial price.
     *
     * @param  array<int, array{name: string, price: float}>  $springs
     */
    private function seedSoftBinding(array $springs): void
    {
        $category = ServiceCategory::factory()->create([
            'name'          => "Палітурка м'яка",
            'is_active'     => true,
            'available_for' => ['internal', 'commercial'],
        ]);

        $service = Service::factory()->create([
            'name'                => 'Палітурка на пружині',
            'type'                => 'constructor',
            'service_category_id' => $category->id,
            'is_active'           => true,
        ]);

        $group = ServiceParameterGroup::factory()->create([
            'service_id'  => $service->id,
            'name'        => 'Пружина',
            'is_required' => true,
            'sort_order'  => 20,
        ]);

        foreach ($springs as $i => $spring) {
            ServiceParameterOption::factory()->create([
                'group_id'     => $group->id,
                'name'         => $spring['name'],
                'price_markup' => $spring['price'],
                'sort_order'   => ($i + 1) * 10,
                'is_active'    => true,
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function publishedCategories(): array
    {
        $response = $this->get(route('price-list'));
        $response->assertOk();

        // Inertia hands the root view a `page` array; reading it here keeps the
        // assertion independent of the asset-version handshake.
        return $response->viewData('page')['props']['categories'] ?? [];
    }

    private function findCategory(string $name): ?array
    {
        foreach ($this->publishedCategories() as $category) {
            if (($category['name'] ?? null) === $name) {
                return $category;
            }
        }

        return null;
    }

    /**
     * The one that matters: change the price, the page must follow.
     */
    public function test_published_soft_binding_prices_come_from_the_database(): void
    {
        $this->seedSoftBinding([
            ['name' => '6 мм (до 25 арк.)', 'price' => 61.00],
            ['name' => '8 мм (до 45 арк.)', 'price' => 72.00],
        ]);

        $category = $this->findCategory("Палітурка м'яка");
        $this->assertNotNull($category, 'The soft binding category should be published.');

        $prices = array_column($category['tier_rows'] ?? [], 'price');

        $this->assertEqualsCanonicalizing(
            [61.00, 72.00],
            array_map('floatval', $prices),
            'The page must publish the prices held in the database, not the ones '
            . 'transcribed into the controller when it was written.',
        );
    }

    /**
     * The labels have to follow too — a price is meaningless without the
     * band it applies to, and the bands were transcribed alongside the prices.
     */
    public function test_published_soft_binding_bands_come_from_the_database(): void
    {
        $this->seedSoftBinding([
            ['name' => '6 мм (до 30 арк.)',  'price' => 45.00],
            ['name' => '10 мм (до 70 арк.)', 'price' => 55.00],
        ]);

        $category = $this->findCategory("Палітурка м'яка");
        $this->assertNotNull($category);

        $labels = implode(' | ', array_column($category['tier_rows'] ?? [], 'label'));

        $this->assertStringContainsString('30', $labels, 'Band from the database is missing.');
        $this->assertStringContainsString('70', $labels, 'Band from the database is missing.');
        $this->assertStringNotContainsString(
            '25',
            $labels,
            'A band nobody configured is being published.',
        );
    }

    /**
     * Scanning was two literals — А4 for 6 ₴, А3 for 10 ₴.
     */
    public function test_published_scanning_prices_come_from_the_database(): void
    {
        $category = ServiceCategory::factory()->create([
            'name'          => 'Сканування',
            'is_active'     => true,
            'available_for' => ['commercial'],
        ]);

        Service::factory()->create([
            'name'                  => 'Сканування А4',
            'type'                  => 'static',
            'service_category_id'   => $category->id,
            'base_price_commercial' => 8.50,
            'is_active'             => true,
        ]);

        $published = $this->findCategory('Сканування');
        $this->assertNotNull($published);

        $prices = array_map('floatval', array_column($published['simple_rows'] ?? [], 'price'));

        $this->assertContains(8.50, $prices, 'The configured scanning price is not published.');
        $this->assertNotContains(6.0, $prices, 'A hardcoded scanning price is still being published.');
    }

    /**
     * Lamination is priced per film type, not per paper. The paper-consolidation
     * step keyed rows by paper group + fill, which is the same empty pair for
     * every film — so all three collapsed into whichever came first.
     */
    public function test_every_lamination_film_type_is_published(): void
    {
        $category = ServiceCategory::factory()->create([
            'name'          => 'Ламінування',
            'is_active'     => true,
            'available_for' => ['commercial'],
        ]);

        $service = Service::factory()->create([
            'name'                => 'Ламінування',
            'type'                => 'constructor',
            'service_category_id' => $category->id,
            'is_active'           => true,
        ]);

        $formatGroup = ServiceParameterGroup::factory()->create([
            'service_id'  => $service->id,
            'name'        => 'Формат',
            'is_required' => true,
            'sort_order'  => 10,
        ]);

        $filmGroup = ServiceParameterGroup::factory()->create([
            'service_id'  => $service->id,
            'name'        => 'Тип плівки',
            'is_required' => true,
            'sort_order'  => 20,
        ]);

        $films = [
            'А4' => ['100 мкм (глянець)' => 27.0, '100 мкм (мат)' => 30.0, '200 мкм (глянець)' => 32.0],
            'А3' => ['100 мкм (глянець)' => 35.0, '100 мкм (мат)' => 40.0, '200 мкм (глянець)' => 45.0],
        ];

        $sort = 1;
        foreach ($films as $format => $options) {
            $formatOption = ServiceParameterOption::factory()->create([
                'group_id'     => $formatGroup->id,
                'name'         => $format,
                'price_markup' => 0,
                'sort_order'   => $sort * 10,
                'is_active'    => true,
            ]);

            foreach ($options as $label => $price) {
                ServiceParameterOption::factory()->create([
                    'group_id'     => $filmGroup->id,
                    'name'         => "{$format}: {$label}",
                    'price_markup' => $price,
                    'sort_order'   => $sort++,
                    'is_active'    => true,
                    'depends_on'   => [
                        'group_id'   => $formatGroup->id,
                        'option_ids' => [$formatOption->id],
                    ],
                ]);
            }
        }

        $published = $this->findCategory('Ламінування');
        $this->assertNotNull($published);

        $rows = [];
        foreach ($published['paper_groups'] ?? [] as $group) {
            foreach ($group['rows'] as $row) {
                $rows[$row['fill']] = $row['prices'];
            }
        }

        $this->assertCount(3, $rows, 'All three film types must be published, not just the first.');

        $this->assertNotTrue(
            $published['print_note'] ?? false,
            'Lamination is priced per film, so the single-sided/double-sided note does not apply.',
        );

        foreach (['100 мкм (глянець)' => [27, 35], '100 мкм (мат)' => [30, 40], '200 мкм (глянець)' => [32, 45]] as $label => [$a4, $a3]) {
            $this->assertArrayHasKey($label, $rows, "Film type {$label} is missing.");
            $this->assertEquals($a4, $rows[$label]['А4'], "Wrong А4 price for {$label}.");
            $this->assertEquals($a3, $rows[$label]['А3'], "Wrong А3 price for {$label}.");
        }
    }

    /**
     * The other side of the same rule: a print category is priced per side,
     * so it keeps the note.
     */
    public function test_a_print_category_keeps_the_single_sided_note(): void
    {
        $category = ServiceCategory::factory()->create([
            'name'          => 'Чорно-білий друк',
            'is_active'     => true,
            'available_for' => ['commercial'],
        ]);

        $service = Service::factory()->create([
            'name'                => 'Чорно-білий друк',
            'type'                => 'constructor',
            'service_category_id' => $category->id,
            'is_active'           => true,
        ]);

        $formatGroup = ServiceParameterGroup::factory()->create([
            'service_id'  => $service->id,
            'name'        => 'Формат',
            'is_required' => true,
            'sort_order'  => 10,
        ]);

        $fillGroup = ServiceParameterGroup::factory()->create([
            'service_id'  => $service->id,
            'name'        => 'Заповненість',
            'is_required' => false,
            'sort_order'  => 30,
        ]);

        $a4 = ServiceParameterOption::factory()->create([
            'group_id'     => $formatGroup->id,
            'name'         => 'А4',
            'price_markup' => 0,
            'sort_order'   => 10,
            'is_active'    => true,
        ]);

        foreach (['до 50%' => 3.0, '51-100%' => 6.0] as $label => $price) {
            ServiceParameterOption::factory()->create([
                'group_id'     => $fillGroup->id,
                'name'         => $label,
                'price_markup' => $price,
                'sort_order'   => 10,
                'is_active'    => true,
                'depends_on'   => [
                    'group_id'   => $formatGroup->id,
                    'option_ids' => [$a4->id],
                ],
            ]);
        }

        $published = $this->findCategory('Чорно-білий друк');
        $this->assertNotNull($published);
        $this->assertTrue(
            $published['print_note'] ?? false,
            'Print is priced per side — the note belongs here.',
        );
    }

    /**
     * A category with nothing priced in it must not fall back to literals.
     */
    public function test_an_unconfigured_binding_category_publishes_no_invented_prices(): void
    {
        ServiceCategory::factory()->create([
            'name'          => "Палітурка м'яка",
            'is_active'     => true,
            'available_for' => ['commercial'],
        ]);

        $category = $this->findCategory("Палітурка м'яка");

        if ($category === null) {
            $this->assertNull($category);

            return;
        }

        $this->assertEmpty(
            $category['tier_rows'] ?? [],
            'Nothing is configured, so there is no price to publish.',
        );
    }
}
