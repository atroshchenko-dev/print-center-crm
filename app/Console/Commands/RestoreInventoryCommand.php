<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use Illuminate\Console\Command;

class RestoreInventoryCommand extends Command
{
    protected $signature = 'inventory:restore-from-movements';

    protected $description = 'Restore current_quantity and avg_cost from inventory_movements (after seeder reset)';

    public function handle(): int
    {
        $this->info('🔄 Відновлення залишків з журналу рухів...');

        $items = InventoryItem::whereHas('movements')->get();
        $restored = 0;

        foreach ($items as $item) {
            // Recalculate from all movements
            $movements = InventoryMovement::where('inventory_item_id', $item->id)
                ->orderBy('created_at')
                ->get();

            $qty = 0.0;
            $totalValue = 0.0;

            foreach ($movements as $mov) {
                $movQty = (float) $mov->quantity;

                // A reversal is not a withdrawal. It cancels one receipt, so it
                // takes back that receipt's own cost — the proportional branch
                // below removes value at the running average, which would leave
                // the average exactly where the cancelled receipt put it.
                if ($mov->type === 'reversal') {
                    $totalValue -= (float) $mov->total_cost;
                    $qty += $movQty; // movQty is negative

                    continue;
                }

                if ($movQty > 0) {
                    // Incoming: the receipt's own cost joins the value pool.
                    $totalValue += (float) $mov->total_cost;
                    $qty += $movQty;

                    continue;
                }

                // Outgoing: AVCO issues at the running average, so the average
                // itself does not move — the pool shrinks in proportion.
                //
                // This used to be decided on the quantity *after* the
                // deduction, which skipped the whole branch whenever an item
                // was emptied to exactly zero. The value stayed in the pool
                // with nothing to back it, and the next receipt averaged
                // against it: an item bought at 5 ₴, run to zero and bought
                // again at 5 ₴ came back valued at 10 ₴.
                if ($qty > 0 && $totalValue > 0) {
                    $totalValue -= abs($movQty) * ($totalValue / $qty);
                }

                $qty += $movQty; // movQty is negative
            }

            $qty = max(0.0, $qty);
            $totalValue = max(0.0, $totalValue);
            $avgCost = $qty > 0 ? round($totalValue / $qty, 4) : 0;

            if ($item->current_quantity != $qty || $item->avg_cost != $avgCost) {
                $oldQty = $item->current_quantity;
                $item->update([
                    'current_quantity' => $qty,
                    'avg_cost'         => $avgCost,
                ]);
                $this->info("✅ {$item->name}: {$oldQty} → {$qty} шт, avg_cost: {$avgCost} ₴");
                $restored++;
            }
        }

        if ($restored === 0) {
            $this->info('Всі залишки вже коректні.');
        } else {
            $this->newLine();
            $this->info("🏁 Відновлено {$restored} позицій.");
        }

        return 0;
    }
}
