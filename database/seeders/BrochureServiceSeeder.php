<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

/**
 * Brochure service seeder.
 *
 * Creates a single brochure-type service under the "Брошури" category.
 * The brochure uses a specialized BrochureConstructor UI (not the cascading constructor).
 * Paper selection and pricing are handled dynamically from inventory.
 */
class BrochureServiceSeeder extends Seeder
{
    public function run(): void
    {
        $category = ServiceCategory::where('name', 'Брошури')->first();
        if (! $category) {
            $this->command->warn("⚠ Category 'Брошури' not found, skipping.");
            return;
        }

        Service::updateOrCreate(
            [
                'service_category_id' => $category->id,
                'name'                => 'Брошура (скоба)',
            ],
            [
                'type'                  => 'brochure',
                'base_price_commercial' => 0,
                'base_price_cost'       => 0,
                'counter_type'          => 'none',
                'clicks_per_unit'       => 0,
                'is_active'             => true,
            ]
        );

        $this->command->info('✅ Brochure service seeded: "Брошура (скоба)"');

        // Auto-flush cached reference data
        app(\App\Services\ReferenceDataService::class)->flush();
    }
}
