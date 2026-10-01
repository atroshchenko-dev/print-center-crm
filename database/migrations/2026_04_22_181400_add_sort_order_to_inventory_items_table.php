<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
            $table->index('sort_order');
        });

        // Back-fill existing items: sequential sort_order per category, ordered by name
        $items = DB::table('inventory_items')
            ->whereNull('deleted_at')
            ->orderBy('inventory_category_id')
            ->orderBy('name')
            ->get();

        $categoryCounters = [];
        foreach ($items as $item) {
            $catId = $item->inventory_category_id ?? 0;
            $categoryCounters[$catId] = ($categoryCounters[$catId] ?? 0) + 1;
            DB::table('inventory_items')
                ->where('id', $item->id)
                ->update(['sort_order' => $categoryCounters[$catId]]);
        }
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropIndex(['sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
