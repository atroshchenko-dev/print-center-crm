<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed all reference data for the CRM Print application.
     *
     * Run: php artisan migrate:fresh --seed
     */
    public function run(): void
    {
        $this->call([
            // Users
            UserSeeder::class,

            // Equipment (printers, riso)
            EquipmentSeeder::class,

            // Materials (click costs)
            MaterialSeeder::class,

            // Service categories & references
            ServiceCategorySeeder::class,
            UniversityRefSeeder::class,

            // Inventory (categories → items → paper subcategories)
            InventoryCategorySeeder::class,
            InventoryItemSeeder::class,
            PaperSubcategorySeeder::class,

            // Services (static + scanning + constructor + brochure + hard binding + diplomas + business cards)
            StaticServiceSeeder::class,
            ScanningServiceSeeder::class,
            ServiceConstructorSeeder::class,
            PrintConstructorSeeder::class,
            BrochureServiceSeeder::class,
            HardBindingConstructorSeeder::class,
            DiplomaConstructorSeeder::class,
            BusinessCardConstructorSeeder::class,

            // Riso pricing tiers
            RisoPriceTierSeeder::class,
        ]);
    }
}
