<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\Service;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use Illuminate\Database\Seeder;

/**
 * Cleans all services and inventory items for fresh re-seeding.
 *
 * SAFE: Keeps categories, equipment, materials, university refs, riso tiers.
 * DESTRUCTIVE: Force-deletes services, constructor groups/options, inventory items.
 *
 * Usage: php artisan db:seed --class=CleanReferenceDataSeeder
 */
class CleanReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->warn('🧹 Cleaning services and inventory items...');

        // ─── Clean Constructor (options → groups → services) ─────
        $deletedOptions = ServiceParameterOption::withTrashed()->forceDelete();
        $this->command->info("  Deleted {$deletedOptions} parameter options");

        $deletedGroups = ServiceParameterGroup::withTrashed()->forceDelete();
        $this->command->info("  Deleted {$deletedGroups} parameter groups");

        $deletedServices = Service::withTrashed()->forceDelete();
        $this->command->info("  Deleted {$deletedServices} services");

        // ─── Clean Inventory Items (keep categories) ─────────────
        $deletedItems = InventoryItem::withTrashed()->forceDelete();
        $this->command->info("  Deleted {$deletedItems} inventory items");

        $this->command->info('✅ Clean complete. Ready for fresh data.');
    }
}
