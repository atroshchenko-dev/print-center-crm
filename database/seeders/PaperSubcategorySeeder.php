<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use Illuminate\Database\Seeder;

class PaperSubcategorySeeder extends Seeder
{
    /**
     * Paper subcategory constants — used in seeders and UI grouping.
     */
    public const REGULAR  = 'Звичайний папір';
    public const COLORED  = 'Кольоровий';
    public const COATED   = 'Крейдований';
    public const DESIGNER = 'Диз. картон';

    /**
     * Classification rules: keyword → subcategory mapping (order matters — first match wins).
     */
    private const CLASSIFICATION_RULES = [
        'дизайнерський' => self::DESIGNER,
        'Картон'        => self::DESIGNER,
        'крейдований'   => self::COATED,
        'пастельний'    => self::COLORED,
    ];

    public function run(): void
    {
        $paperCategoryId = InventoryCategory::where('name', InventoryCategory::PAPER_SLUG)->value('id');

        if (! $paperCategoryId) {
            $this->command->warn('⚠ Paper category not found, skipping subcategory assignment.');
            return;
        }

        $items = InventoryItem::where('inventory_category_id', $paperCategoryId)->get();
        $updated = 0;

        foreach ($items as $item) {
            $subcategory = self::classify($item->name);

            if ($item->subcategory !== $subcategory) {
                $item->update(['subcategory' => $subcategory]);
                $updated++;
            }
        }

        $this->command->info("📂 Paper subcategories assigned: {$updated} items updated.");
    }

    /**
     * Classify a paper item name into a subcategory.
     */
    public static function classify(string $name): string
    {
        foreach (self::CLASSIFICATION_RULES as $keyword => $subcategory) {
            if (str_contains($name, $keyword)) {
                return $subcategory;
            }
        }

        return self::REGULAR;
    }

    /**
     * Get all subcategory labels in display order.
     */
    public static function orderedLabels(): array
    {
        return [
            self::REGULAR,
            self::COLORED,
            self::COATED,
            self::DESIGNER,
        ];
    }
}
