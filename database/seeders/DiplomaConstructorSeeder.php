<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use Illuminate\Database\Seeder;

/**
 * Seeds a single 'diploma' type service for the Дипломи/Додатки category.
 *
 * Unlike constructor services, the 'diploma' type uses a custom UI
 * (DiplomaConstructor.vue) with block-based form and DiplomaPricingService
 * for snapshot building — no parameter groups needed.
 */
class DiplomaConstructorSeeder extends Seeder
{
    public function run(): void
    {
        $cat = ServiceCategory::where('name', 'Дипломи/Додатки')->first();
        if (! $cat) {
            $this->command->warn('⚠ Category "Дипломи/Додатки" not found, skipping.');
            return;
        }

        // Create single diploma-type service
        Service::updateOrCreate(
            ['service_category_id' => $cat->id, 'name' => 'Дипломи/Додатки'],
            [
                'type'                  => 'diploma',
                'base_price_commercial' => 0,
                'base_price_cost'       => 0,
                'counter_type'          => 'color',
                'clicks_per_unit'       => 0,
                'is_active'             => true,
            ]
        );

        // ── Cleanup old services in this category ──
        $this->cleanupOldServices($cat);

        // Auto-flush cached reference data
        app(\App\Services\ReferenceDataService::class)->flush();
        $this->command->info('🔄 Reference data cache flushed.');
        $this->command->info('✅ Дипломи/Додатки: 1 diploma-type service (custom UI).');
    }

    /**
     * Soft-delete old constructor/static services and their parameter groups.
     */
    private function cleanupOldServices(ServiceCategory $cat): void
    {
        $oldServices = Service::where('service_category_id', $cat->id)
            ->where(function ($q) {
                $q->where('type', '!=', 'diploma')
                  ->orWhere('name', '!=', 'Дипломи/Додатки');
            })
            ->whereNull('deleted_at')
            ->get();

        foreach ($oldServices as $old) {
            // Soft-delete parameter groups and options
            foreach ($old->parameterGroups as $group) {
                ServiceParameterOption::where('group_id', $group->id)
                    ->whereNull('deleted_at')
                    ->each(fn ($o) => $o->delete());
                $group->delete();
            }
            $old->delete();
            $typeName = $old->type?->value ?? 'unknown';
            $this->command->info("🗑️  Old service «{$old->name}» (type: {$typeName}) cleaned up.");
        }
    }
}
