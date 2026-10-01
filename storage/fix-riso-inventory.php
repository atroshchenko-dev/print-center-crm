<?php

/**
 * Fix RISO inventory deduction bug (v3).
 *
 * Usage:
 *   php artisan tinker storage/fix-riso-inventory.php
 */

use App\Models\OrderItem;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\AuditLog;
use App\Models\User;

echo "=== RISO Snapshot Fix ===\n\n";

$risoItems = OrderItem::with('order')
    ->whereRaw("service_snapshot->>'service_type' = 'riso'")
    ->get();

echo "Found " . $risoItems->count() . " RISO order items.\n\n";

$fixed = 0;
$itemIds = [];

foreach ($risoItems as $oi) {
    $snap = $oi->service_snapshot;
    $ded = $snap['inventory_deductions'] ?? [];
    if (empty($ded)) {
        continue;
    }

    $copies = (int) $oi->quantity;
    $fmt = strtoupper($snap['riso_params']['format'] ?? 'A3');
    $oldQty = (float) $ded[0]['qty'];
    $perCopy = ($fmt === 'A4') ? 0.5 : 1.0;

    if ($oldQty <= 1.0) {
        continue;
    }

    $invId = $ded[0]['inventory_item_id'];
    if ($invId) {
        $itemIds[$invId] = true;
    }

    $num = $oi->order->order_number ?? '?';
    echo "  FIX {$num} item#{$oi->id}: {$copies}x {$fmt} | qty {$oldQty} -> {$perCopy}\n";

    $snap['inventory_deductions'][0]['qty'] = $perCopy;
    $oi->service_snapshot = $snap;
    $oi->saveQuietly();
    $fixed++;
}

echo "\nFixed: {$fixed} snapshots\n\n";

// Show movements for affected inventory items
foreach (array_keys($itemIds) as $iid) {
    $inv = InventoryItem::find($iid);
    if (!$inv) {
        continue;
    }

    echo "--- {$inv->name} (id={$iid}) ---\n";
    echo "current_quantity: {$inv->current_quantity}\n\n";

    $mvs = InventoryMovement::where('inventory_item_id', $iid)->orderBy('id')->get();
    $bal = 0;

    foreach ($mvs as $m) {
        $q = (float) $m->quantity;
        $bal += $q;
        $n = mb_substr($m->notes ?? '', 0, 50);
        echo sprintf("  #%-3s %-13s %+10.1f  bal=%8.1f  %s\n", $m->id, $m->type, $q, $bal, $n);
    }

    echo "\nJournal sum: {$bal}\n";
    echo "DB current:  {$inv->current_quantity}\n";
    echo "Gap: " . ((float) $inv->current_quantity - $bal) . "\n\n";
}

// Audit
$admin = User::where('role', 'admin')->first();
if ($admin && $fixed > 0) {
    AuditLog::record(
        eventType: 'riso_bug_fix',
        user: $admin,
        description: "Fixed {$fixed} RISO snapshots: qty total->per-copy",
        shiftId: null,
        meta: ['fixed' => $fixed],
    );
}

echo "Done! Now set correct stock manually.\n";
