<?php

/**
 * Fix retro diploma orders: add missing "1 арк 4+0" block
 * for master and phd supplements.
 *
 * Usage: php artisan tinker storage/fix-diploma-blocks.php
 *
 * This script is idempotent — safe to run multiple times.
 */

use App\Models\OrderItem;
use App\Services\DiplomaPricingService;

$targetTypes = ['master', 'phd'];
$blockToAdd = ['sheets' => 1, 'mode' => '4+0'];

// Find all diploma order items from backdated orders
$items = OrderItem::whereHas('order', fn ($q) => $q->where('is_backdated', true))
    ->get()
    ->filter(fn ($item) => ($item->service_snapshot['service_type'] ?? '') === 'diploma');

echo "Found {$items->count()} diploma order items in backdated orders.\n";

$fixed = 0;

foreach ($items as $item) {
    $snapshot = $item->service_snapshot;
    $supplements = $snapshot['diploma_params']['supplements'] ?? [];
    $changed = false;

    foreach ($supplements as $si => $supp) {
        if (!in_array($supp['type'] ?? '', $targetTypes)) {
            continue;
        }

        // Check if 4+0 block already exists
        $has4plus0 = false;
        foreach ($supp['blocks'] ?? [] as $block) {
            if (($block['mode'] ?? '') === '4+0') {
                $has4plus0 = true;
                break;
            }
        }

        if ($has4plus0) {
            echo "  → Order #{$item->order_id}, {$supp['type']}: already has 4+0 block, skipping.\n";
            continue;
        }

        // Insert 4+0 block before the last block (0+0)
        // Pattern: [4+4, ...] → [4+4, 4+0, 0+0]
        $blocks = $supp['blocks'] ?? [];
        $lastBlock = end($blocks);
        if (($lastBlock['mode'] ?? '') === '0+0') {
            // Insert before last
            array_splice($blocks, count($blocks) - 1, 0, [$blockToAdd]);
        } else {
            // Append at end
            $blocks[] = $blockToAdd;
        }

        $supplements[$si]['blocks'] = $blocks;
        $changed = true;
        echo "  ✓ Order #{$item->order_id}, {$supp['type']}: added 1×4+0 block.\n";
    }

    if (!$changed) {
        continue;
    }

    // Update snapshot with new blocks
    $snapshot['diploma_params']['supplements'] = $supplements;

    // Recalculate costs using DiplomaPricingService
    $service = \App\Models\Service::find($snapshot['service_id']);
    if ($service) {
        $pricingService = new DiplomaPricingService();
        $newSnapshot = $pricingService->buildSnapshot($service, $snapshot['diploma_params'], 1);
        $item->service_snapshot = $newSnapshot;
    } else {
        // Fallback: just save blocks without recalculation
        $item->service_snapshot = $snapshot;
        echo "  ⚠ Service #{$snapshot['service_id']} not found, saved blocks without recalc.\n";
    }

    $item->save();
    $fixed++;
}

echo "\nDone! Fixed {$fixed} order items.\n";
