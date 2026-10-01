<?php

declare(strict_types=1);

use App\Models\ServiceParameterOption;
use Illuminate\Database\Migrations\Migration;

/**
 * Recalculate cost_markup for all constructor options that have inventory links.
 * This picks up the new A3→A4 fallback cost logic in computeConsumablesCost().
 */
return new class extends Migration
{
    public function up(): void
    {
        ServiceParameterOption::whereNotNull('inventory_item_id')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->each(function (ServiceParameterOption $option): void {
                $option->save(); // triggers saving hook → recomputes cost_markup
            });
    }

    /**
     * Intentionally a no-op, and correct as such (audit finding M-5).
     *
     * cost_markup is derived, not entered: the model's saving() hook recomputes
     * it from the linked inventory item on every save. There is no previous
     * value to restore — reverting the code is what changes the numbers back,
     * and the next save applies it.
     */
    public function down(): void
    {
        //
    }
};
