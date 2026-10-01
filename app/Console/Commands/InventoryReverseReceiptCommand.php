<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ServiceParameterOption;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Console\Command;

/**
 * Take back a stock receipt booked by mistake.
 *
 * The argument is a fragment of the item name rather than a movement id: the
 * owner runs this from what the Склад screen shows him, and ids are not on it.
 * Looking one up would mean a tinker session before the command that exists to
 * spare him tinker sessions. Two matches stop the run — choosing between them
 * is exactly the decision a command must not make on its own.
 *
 * inventory:stocktake is the other correction in this family and stops short of
 * prices on purpose: a recount restates how many, never how much. This one has
 * to touch both, because a receipt that never happened brought a cost in with
 * it.
 */
class InventoryReverseReceiptCommand extends Command
{
    protected $signature = 'inventory:reverse-receipt
        {item : Частина назви товару}
        {--dry-run : Порахувати й показати, нічого не записуючи}
        {--force : Провести сторно попри пізніші рухи по позиції}';

    protected $description = 'Reverse a stock receipt booked by mistake';

    public function handle(InventoryService $service): int
    {
        $needle = (string) $this->argument('item');

        $matches = InventoryItem::where('is_active', true)
            ->where('name', 'ilike', '%'.$needle.'%')
            ->orderBy('name')
            ->get();

        if ($matches->isEmpty()) {
            $this->error("Товару за запитом «{$needle}» не знайдено.");

            return 1;
        }

        if ($matches->count() > 1) {
            $this->error("За запитом «{$needle}» знайдено кілька товарів — уточніть запит:");
            foreach ($matches as $match) {
                $this->line('   • '.$match->name.' — '.
                    $this->quantity($match->current_quantity).' '.$match->unit);
            }

            return 1;
        }

        $item = $matches->first();

        $receipt = InventoryMovement::where('inventory_item_id', $item->id)
            ->where('type', 'in')
            ->orderByDesc('id')
            ->first();

        if (! $receipt) {
            $this->error("По товару «{$item->name}» оприбуткувань не було.");

            return 1;
        }

        $admin = User::where('role', UserRole::Admin->value)->first();
        if (! $admin) {
            $this->error('Admin user not found!');

            return 1;
        }

        $quantity = (float) $receipt->quantity;
        $quantityNow = (float) $item->current_quantity;
        $quantityAfter = $quantityNow - $quantity;
        $valueAfter = max(0.0, $quantityNow * (float) $item->avg_cost - (float) $receipt->total_cost);
        $avgCostAfter = $quantityAfter > 0 ? $valueAfter / $quantityAfter : (float) $item->avg_cost;

        $options = ServiceParameterOption::where('inventory_item_id', $item->id)->count();

        $this->newLine();
        $this->line("Товар:   {$item->name}");
        $this->line('Прихід:  #'.$receipt->id.' — '.$this->quantity($quantity).' '.$item->unit.
            ' на '.number_format((float) $receipt->total_cost, 2, '.', ' ').' ₴, '.
            $receipt->created_at->format('d.m.Y H:i').
            ($receipt->user ? ', '.$receipt->user->name : ''));
        if ($receipt->notes) {
            $this->line("Нотатка: {$receipt->notes}");
        }
        $this->newLine();
        $this->line('Залишок:      '.$this->quantity($quantityNow).' → '.
            $this->quantity($quantityAfter).' '.$item->unit);
        $this->line('Собівартість: '.round((float) $item->avg_cost, 4).' → '.
            round($avgCostAfter, 4).' ₴');
        $this->line('Опцій калькулятора отримають нову собівартість: '.$options);
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->info('[dry-run] Нічого не записано.');

            return 0;
        }

        try {
            $movement = $service->reverseReceipt($receipt, $admin, (bool) $this->option('force'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $item->refresh();

        // Printed after the transaction commits: a «✅» for a line rolled back a
        // moment later is worse than no line at all.
        $this->info("✅ Сторно проведено рухом #{$movement->id}.");
        $this->line('   Залишок: '.$this->quantity($item->current_quantity).' '.$item->unit.
            ', собівартість: '.round((float) $item->avg_cost, 4).' ₴');

        return 0;
    }

    /**
     * decimal(12,4) interpolates as «300.0000». Nobody has three hundred
     * point-zero-zero-zero-zero springs.
     */
    private function quantity(float|string $value): string
    {
        return (string) round((float) $value, 4);
    }
}
