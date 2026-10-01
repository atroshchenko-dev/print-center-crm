<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Split coated paper into gloss and matte.
 *
 * The warehouse counts these separately — a stocktake gives "А3 250 гл: 97,
 * А3 250 мат: 335" — but inventory carried a single "(крейдований)" item per
 * format+weight, so the two piles shared one number and neither was true.
 *
 * The existing rows are RENAMED rather than replaced: service_parameter_options
 * points at them by id, so renaming keeps every constructor binding intact and
 * silently resolves it to the gloss variant. The matte rows are new, and no
 * option points at them yet — wiring those into the print and business-card
 * constructors is a separate round.
 *
 * On a fresh database this is a no-op: nothing is seeded yet at migrate time,
 * and InventoryItemSeeder already creates both variants.
 */
return new class extends Migration
{
    /** @var list<array{format: string, weight: int}> */
    private const VARIANTS = [
        ['format' => 'А3', 'weight' => 150],
        ['format' => 'А3', 'weight' => 250],
        ['format' => 'А3', 'weight' => 300],
        ['format' => 'А4', 'weight' => 150],
        ['format' => 'А4', 'weight' => 250],
        ['format' => 'А4', 'weight' => 300],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::VARIANTS as ['format' => $format, 'weight' => $weight]) {
                $this->renameToGloss($format, $weight);
                $this->createMatte($format, $weight);
            }

            $this->mapMatteConversions();
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (self::VARIANTS as ['format' => $format, 'weight' => $weight]) {
                $matte = DB::table('inventory_items')
                    ->where('name', $this->name($format, $weight, 'мат'))
                    ->first();

                if (! $matte) {
                    continue;
                }

                // A matte item that has already been received or written off is
                // real warehouse history. Dropping it would orphan append-only
                // movement rows, so it is soft-deleted and its stock left alone.
                $hasMovements = DB::table('inventory_movements')
                    ->where('inventory_item_id', $matte->id)
                    ->exists();

                DB::table('inventory_items')
                    ->where('convertible_from_id', $matte->id)
                    ->update(['convertible_from_id' => null, 'conversion_ratio' => 0]);

                if ($hasMovements) {
                    DB::table('inventory_items')
                        ->where('id', $matte->id)
                        ->update(['deleted_at' => now(), 'is_active' => false]);
                } else {
                    DB::table('inventory_items')->where('id', $matte->id)->delete();
                }
            }

            foreach (self::VARIANTS as ['format' => $format, 'weight' => $weight]) {
                DB::table('inventory_items')
                    ->where('name', $this->name($format, $weight, 'глянець'))
                    ->update(['name' => $this->baseName($format, $weight), 'updated_at' => now()]);
            }
        });
    }

    private function renameToGloss(string $format, int $weight): void
    {
        DB::table('inventory_items')
            ->where('name', $this->baseName($format, $weight))
            ->update([
                'name'       => $this->name($format, $weight, 'глянець'),
                'updated_at' => now(),
            ]);
    }

    private function createMatte(string $format, int $weight): void
    {
        $matteName = $this->name($format, $weight, 'мат');

        if (DB::table('inventory_items')->where('name', $matteName)->exists()) {
            return;
        }

        // The gloss row is the template: it already carries the category,
        // subcategory and unit this paper belongs to.
        $gloss = DB::table('inventory_items')
            ->where('name', $this->name($format, $weight, 'глянець'))
            ->first();

        if (! $gloss) {
            return;
        }

        DB::table('inventory_items')->insert([
            'inventory_category_id' => $gloss->inventory_category_id,
            'subcategory'           => $gloss->subcategory,
            'name'                  => $matteName,
            'unit'                  => $gloss->unit,
            'current_quantity'      => 0,
            'empty_quantity'        => 0,
            'avg_cost'              => 0,
            'refill_cost'           => 0,
            'min_quantity'          => $gloss->min_quantity,
            'is_active'             => true,
            'sort_order'            => $gloss->sort_order,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    /**
     * One A3 matte sheet cuts into two A4 matte sheets — same rule the gloss
     * items already carry, and it must not cross over to the other finish.
     */
    private function mapMatteConversions(): void
    {
        foreach ([150, 250, 300] as $weight) {
            $a3 = DB::table('inventory_items')->where('name', $this->name('А3', $weight, 'мат'))->value('id');
            $a4 = DB::table('inventory_items')->where('name', $this->name('А4', $weight, 'мат'))->value('id');

            if ($a3 && $a4) {
                DB::table('inventory_items')->where('id', $a4)->update([
                    'convertible_from_id' => $a3,
                    'conversion_ratio'    => 2.00,
                    'updated_at'          => now(),
                ]);
            }
        }
    }

    private function baseName(string $format, int $weight): string
    {
        return "Папір {$format} {$weight} г/м² (крейдований)";
    }

    private function name(string $format, int $weight, string $finish): string
    {
        return "Папір {$format} {$weight} г/м² (крейдований, {$finish})";
    }
};
