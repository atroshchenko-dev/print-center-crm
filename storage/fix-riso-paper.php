<?php

/**
 * Fix paper_name AND recalculate costs in riso_params for two specific order items.
 *
 * Orders INT-2508-155 and INT-2508-156:
 *   - Second item (A4, 1+0, 25 sheets, 50 qty) should use
 *     160g pink paper instead of 80g standard.
 *
 * Run: php artisan tinker storage/fix-riso-paper.php
 */

use App\Models\Order;
use App\Models\InventoryItem;

$orderNumbers = ['INT-2508-155', 'INT-2508-156'];
$newPaperName = 'Папір А3 160 г/м² (паст. рожевий)';

// Find actual 160g pink paper cost from inventory
$pinkPaper = InventoryItem::where('name', 'ILIKE', '%160%рожев%')
    ->where('is_active', true)
    ->first();

if (!$pinkPaper) {
    // Fallback: try broader search
    $pinkPaper = InventoryItem::where('name', 'ILIKE', '%160%паст%рож%')
        ->where('is_active', true)
        ->first();
}

$newPaperCostA3 = $pinkPaper ? (float) $pinkPaper->avg_cost : null;

if (!$newPaperCostA3) {
    echo "⚠️  160g pink paper not found in inventory. Listing available items:\n";
    InventoryItem::where('is_active', true)->get()->each(function ($item) {
        echo "   - [{$item->id}] {$item->name} (avg_cost: {$item->avg_cost})\n";
    });
    echo "\nSet \$newPaperCostA3 manually and re-run.\n";
    return;
}

echo "📦 Paper: {$pinkPaper->name} (avg_cost: {$newPaperCostA3})\n\n";

foreach ($orderNumbers as $orderNumber) {
    $order = Order::where('order_number', $orderNumber)->first();

    if (!$order) {
        echo "❌ Order {$orderNumber} not found\n";
        continue;
    }

    $items = $order->items()->orderBy('id')->get();
    $orderTotalDiff = 0;

    foreach ($items as $item) {
        $rp = $item->service_snapshot['riso_params'] ?? null;
        if (!$rp) continue;

        // Match the item: sides=1 (1+0), sheets_a3=25
        if (($rp['sides'] ?? 0) == 1 && ($rp['sheets_a3'] ?? 0) == 25) {
            $oldPaperName = $rp['paper_name'] ?? '?';
            $oldPaperCost = (float) ($rp['paper_cost_a3'] ?? 0);
            $sheetsA3     = (int) $rp['sheets_a3'];
            $sides        = (int) $rp['sides'];
            $costPerCopy  = (float) $rp['cost_per_copy'];
            $markup       = (float) ($rp['markup'] ?? 2.0);
            $quantity     = (int) $item->quantity;

            // Recalculate
            $printCost = $sheetsA3 * $costPerCopy * $sides;
            $newPaperCostTotal = $sheetsA3 * $newPaperCostA3;
            $newTotalCost = round($printCost + $newPaperCostTotal, 2);
            $newUnitCost  = $quantity > 0 ? round($newTotalCost / $quantity, 4) : 0;
            $newTotalCommercial = round($newTotalCost * $markup, 2);
            $newUnitCommercial  = $quantity > 0 ? round($newTotalCommercial / $quantity, 4) : 0;

            $oldTotalCost = (float) $item->total_price_cost;

            // Update snapshot
            $snapshot = $item->service_snapshot;
            $snapshot['riso_params']['paper_name']    = $newPaperName;
            $snapshot['riso_params']['paper_cost_a3'] = $newPaperCostA3;
            $snapshot['unit_price_cost']        = $newUnitCost;
            $snapshot['total_price_cost']       = $newTotalCost;
            $snapshot['unit_price_commercial']  = $newUnitCommercial;
            $snapshot['total_price_commercial'] = $newTotalCommercial;

            // Update inventory deduction to pink paper
            if ($pinkPaper) {
                $snapshot['inventory_deductions'] = [[
                    'inventory_item_id' => $pinkPaper->id,
                    'qty'               => $sheetsA3,
                ]];
            }

            $item->service_snapshot    = $snapshot;
            $item->unit_price_cost     = $newUnitCost;
            $item->total_price_cost    = $newTotalCost;
            $item->save();

            $orderTotalDiff += ($newTotalCost - $oldTotalCost);

            echo "✅ {$orderNumber} item #{$item->id}:\n";
            echo "   Paper: '{$oldPaperName}' → '{$newPaperName}'\n";
            echo "   Paper cost/sheet: {$oldPaperCost} → {$newPaperCostA3}\n";
            echo "   Total cost: {$oldTotalCost} → {$newTotalCost}\n";
        }
    }

    // Update order total
    if ($orderTotalDiff != 0) {
        $oldOrderTotal = (float) $order->total_cost;
        $order->total_cost = round($oldOrderTotal + $orderTotalDiff, 2);
        $order->save();
        echo "   Order total: {$oldOrderTotal} → {$order->total_cost}\n";
    }
    echo "\n";
}

echo "Done.\n";
