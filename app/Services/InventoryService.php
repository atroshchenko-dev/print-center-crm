<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\ServiceParameterOption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryService
{
    /**
     * Record a new stock receipt (Прибуткова накладна).
     * Automatically calculates AVCO (Average Cost).
     */
    public function receiveStock(InventoryItem $item, float $quantity, float $totalCost, User $user, ?string $notes = null): InventoryMovement
    {
        return DB::transaction(function () use ($item, $quantity, $totalCost, $user, $notes) {
            // Lock item to prevent concurrent modifications
            $item = InventoryItem::where('id', $item->id)->lockForUpdate()->firstOrFail();

            $unitCost = $quantity > 0 ? $totalCost / $quantity : 0;

            $movement = InventoryMovement::create([
                'inventory_item_id' => $item->id,
                'type'              => 'in',
                'quantity'          => $quantity,
                'unit_cost'         => $unitCost,
                'total_cost'        => $totalCost,
                'user_id'           => $user->id,
                'notes'             => $notes,
            ]);

            // Calculate AVCO
            // AVCO = (CurrentQty * CurrentAvgCost + TotalCostOfNewReceipt) / (CurrentQty + NewQty)
            $oldTotalValue = $item->current_quantity * $item->avg_cost;
            $newTotalValue = $oldTotalValue + $totalCost;
            $newQuantity = $item->current_quantity + $quantity;

            $newAvgCost = $newQuantity > 0 ? $newTotalValue / $newQuantity : 0;

            $item->update([
                'current_quantity' => $newQuantity,
                'avg_cost'         => round($newAvgCost, 4),
            ]);

            // Refresh cost_markup on all Constructor options linked to this inventory item
            ServiceParameterOption::where('inventory_item_id', $item->id)
                ->cursor()
                ->each(fn ($opt) => $opt->save()); // triggers saving hook → recomputes cost

            return $movement;
        });
    }

    /**
     * Undo a stock receipt booked by mistake (сторно прибуткової накладної).
     *
     * The quantity is the easy half. The cost is the half that has to be said
     * out loud: AVCO issues at the running average, so writing the pieces off
     * as an ordinary deduction would shrink the value pool in proportion and
     * leave the average exactly where the wrong receipt put it. What came in
     * with the receipt — its own total_cost — is what leaves with it.
     *
     * Type `reversal` is deliberately outside the consumption list in
     * ProcurementAdvisorService::consumptionRates(), for the same reason
     * `stocktake` is: a correction that reads as consumption inflates every
     * forecast built on it.
     */
    public function reverseReceipt(InventoryMovement $receipt, User $user, bool $force = false): InventoryMovement
    {
        if ($receipt->type !== 'in') {
            throw new \InvalidArgumentException(
                'Сторнувати можна лише оприбуткування. Рух #'.$receipt->id.' має тип «'.$receipt->type.'».'
            );
        }

        return DB::transaction(function () use ($receipt, $user, $force) {
            // Lock item to prevent concurrent modifications
            $item = InventoryItem::where('id', $receipt->inventory_item_id)->lockForUpdate()->firstOrFail();

            $alreadyReversed = InventoryMovement::where('type', 'reversal')
                ->where('reference_type', InventoryMovement::class)
                ->where('reference_id', $receipt->id)
                ->first();

            if ($alreadyReversed) {
                throw new \RuntimeException(
                    'Прихід #'.$receipt->id.' уже сторновано рухом #'.$alreadyReversed->id.'.'
                );
            }

            // id, not created_at: the journal is append-only, so id is monotonic,
            // while created_at only resolves to the second and two movements can
            // share one.
            $later = InventoryMovement::where('inventory_item_id', $item->id)
                ->where('id', '>', $receipt->id)
                ->count();

            if ($later > 0 && ! $force) {
                throw new \RuntimeException(
                    'Після приходу #'.$receipt->id.' по цій позиції пройшло рухів: '.$later.
                    '. Перевірте їх і повторіть із --force, якщо сторно все одно потрібне.'
                );
            }

            $quantity = (float) $receipt->quantity;
            $quantityNow = (float) $item->current_quantity;
            $quantityAfter = $quantityNow - $quantity;

            // --force waives the movements-after check, never this one: stock
            // that would go negative is a different problem from stock that
            // moved.
            if ($quantityAfter < 0) {
                throw new \RuntimeException(
                    'На складі '.self::countForHumans($quantityNow).', а сторнується '.
                    self::countForHumans($quantity).' — залишок пішов би в мінус.'
                );
            }

            $valueAfter = max(0.0, $quantityNow * (float) $item->avg_cost - (float) $receipt->total_cost);

            // An item reversed down to nothing keeps the cost it last knew.
            // Zeroing it would hand every Constructor option linked to this item
            // a cost of 0 — the calculator would price the spring free.
            $avgCostAfter = $quantityAfter > 0 ? $valueAfter / $quantityAfter : (float) $item->avg_cost;

            $movement = InventoryMovement::create([
                'inventory_item_id' => $item->id,
                'type'              => 'reversal',
                'quantity'          => -$quantity,
                'unit_cost'         => $receipt->unit_cost,
                'total_cost'        => $receipt->total_cost,
                'reference_type'    => InventoryMovement::class,
                'reference_id'      => $receipt->id,
                'user_id'           => $user->id,
                'notes'             => 'Сторно приходу #'.$receipt->id.
                    ($receipt->notes ? ': '.$receipt->notes : ''),
            ]);

            $item->update([
                'current_quantity' => $quantityAfter,
                'avg_cost'         => round($avgCostAfter, 4),
            ]);

            // Refresh cost_markup on all Constructor options linked to this
            // inventory item — avg_cost moved, so the price built on it must too.
            ServiceParameterOption::where('inventory_item_id', $item->id)
                ->cursor()
                ->each(fn ($opt) => $opt->save());

            AuditLog::record(
                eventType: 'inventory_receipt_reversed',
                user: $user,
                description: "Сторно приходу #{$receipt->id}: '{$item->name}' — ".
                    self::countForHumans($quantity).' '.$item->unit.
                    ', собівартість '.round($avgCostAfter, 4).' ₴',
                shiftId: null,
                meta: [
                    'inventory_item_id'    => $item->id,
                    'reversed_movement_id' => $receipt->id,
                    'quantity'             => $quantity,
                    'total_cost'           => (float) $receipt->total_cost,
                    'forced'               => $force,
                ],
            );

            return $movement;
        });
    }

    /**
     * Auto-deduct inventory materials for a completed order.
     *
     * Deduction is clamped at the stock on hand rather than refused, by design —
     * the order is physically already printed, so blocking it would only make
     * the books disagree with reality. But until now the shortfall went to the
     * log and the audit trail and nowhere the operator could see it (audit
     * finding L-4), so `deficits` now comes back alongside the conversions for
     * the caller to surface.
     *
     * @return array{conversions: string[], deficits: string[]}
     */
    public function autoDeductForOrder(Order $order, User $user): array
    {
        $conversions = [];
        $deficits = [];

        DB::transaction(function () use ($order, $user, &$conversions, &$deficits) {
            foreach ($order->items as $orderItem) {
                $snapshot = $orderItem->service_snapshot;
                if (! $snapshot) {
                    continue;
                }

                // Constructor services: deduct via constructor_snapshot
                if (isset($snapshot['constructor_snapshot'])) {
                    foreach ($snapshot['constructor_snapshot'] as $optionData) {
                        if (! empty($optionData['inventory_item_id'])) {
                            $itemId = $optionData['inventory_item_id'];
                            $qtyPerUnit = (float) ($optionData['inventory_qty'] ?? 0);

                            if ($qtyPerUnit > 0) {
                                $totalDeductQty = $qtyPerUnit * $orderItem->quantity;
                                $msg = $this->deductStock($itemId, $totalDeductQty, $order, $user, $deficits);
                                if ($msg) {
                                    $conversions[] = $msg;
                                }
                            }
                        }
                    }
                }

                // Brochure services: deduct via inventory_deductions
                if (isset($snapshot['inventory_deductions'])) {
                    foreach ($snapshot['inventory_deductions'] as $deduction) {
                        if (! empty($deduction['inventory_item_id']) && ($deduction['qty'] ?? 0) > 0) {
                            $totalDeductQty = (float) $deduction['qty'] * $orderItem->quantity;
                            $msg = $this->deductStock($deduction['inventory_item_id'], $totalDeductQty, $order, $user, $deficits);
                            if ($msg) {
                                $conversions[] = $msg;
                            }
                        }
                    }
                }
            }
        });

        return ['conversions' => $conversions, 'deficits' => $deficits];
    }

    /**
     * Internal method to deduct stock safely.
     *
     * If insufficient stock and item has a convertible source (e.g. A3 for A4),
     * automatically converts the needed quantity before deducting.
     *
     * @param  string[]  $deficits  Collects one operator-readable line per shortfall
     * @return string|null Conversion description if auto-conversion occurred, null otherwise.
     */
    private function deductStock(int $itemId, float $quantity, Order $order, User $user, array &$deficits = []): ?string
    {
        $item = InventoryItem::where('id', $itemId)->lockForUpdate()->firstOrFail();
        $conversionMsg = null;

        // Auto-convert from source (e.g. A3) if target (e.g. A4) is insufficient
        if ((float) $item->current_quantity < $quantity && $item->convertible_from_id && $item->conversion_ratio > 0) {
            $conversionMsg = $this->autoConvertIfNeeded($item, $quantity, $order, $user);
            // Reload item after conversion (quantity may have increased)
            $item = InventoryItem::where('id', $itemId)->lockForUpdate()->firstOrFail();
        }

        // Warn and audit-log if deduction will still result in negative stock
        if ((float) $item->current_quantity < $quantity) {
            $deficits[] = sprintf(
                '%s: потрібно %s, на складі %s — списано скільки було.',
                $item->name,
                self::countForHumans($quantity),
                self::countForHumans($item->current_quantity),
            );

            Log::warning(
                "Inventory deficit: item #{$item->id} '{$item->name}' has {$item->current_quantity} ".
                "but {$quantity} requested for order #{$order->order_number}"
            );

            AuditLog::record(
                eventType: 'inventory_deficit',
                user: $user,
                description: "Inventory deficit: '{$item->name}' has {$item->current_quantity} units, ".
                    "but {$quantity} needed for order #{$order->order_number}",
                shiftId: null,
                meta: [
                    'inventory_item_id' => $item->id,
                    'available'         => $item->current_quantity,
                    'requested'         => $quantity,
                    'order_id'          => $order->id,
                ],
            );
        }

        // Clamp actual deduction to available stock to prevent negative quantity (AVCO corruption)
        $actualDeduction = min($quantity, max(0, (float) $item->current_quantity));

        // Record actual (clamped) deduction in movement journal
        $totalCost = $actualDeduction * $item->avg_cost;
        $deficitNote = $actualDeduction < $quantity
            ? " (запитано {$quantity}, списано {$actualDeduction} — дефіцит)"
            : '';

        InventoryMovement::create([
            'inventory_item_id' => $item->id,
            'type'              => 'auto_deduct',
            'quantity'          => -$actualDeduction,
            'unit_cost'         => $item->avg_cost,
            'total_cost'        => $totalCost,
            'reference_type'    => Order::class,
            'reference_id'      => $order->id,
            'user_id'           => $user->id,
            'notes'             => "Автоматичне списання за замовленням #{$order->order_number}{$deficitNote}",
        ]);

        if ($actualDeduction > 0) {
            $quantityBefore = (float) $item->current_quantity;

            $item->decrement('current_quantity', $actualDeduction);
            $item->refresh();

            $quantityAfter = (float) $item->current_quantity;
            $minQuantity = (float) $item->min_quantity;

            // Notify on *crossing* the minimum, not on every deduction below it.
            //
            // The condition used to be tested after each deduction, so an item
            // already under its minimum pinged the chat again on every order that
            // touched it, until somebody restocked. Measured on production
            // 2026-08-04: 23 such messages in 30 days from 3 items — the same
            // three names roughly every 36 hours, all saying what was already
            // said. That is R8-4 in miniature: a monitor that is always red is
            // one nobody reads, and the fourth, real warning gets scrolled past
            // with the rest.
            //
            // Crossing means the item was above the minimum before this deduction
            // and is at or below it after. A receipt that lifts stock back over
            // the minimum arms the signal again.
            if ($minQuantity > 0 && $quantityAfter <= $minQuantity && $quantityBefore > $minQuantity) {
                try {
                    app(TelegramService::class)->lowStock(
                        $item->name,
                        $quantityAfter,
                        $minQuantity,
                    );
                } catch (\Throwable) {
                    // Non-critical — don't fail the order for notification issues
                }
            }
        }

        return $conversionMsg;
    }

    /**
     * Auto-convert stock from source item (e.g. A3) to target item (e.g. A4)
     * when target has insufficient stock.
     *
     * Only converts the amount needed to cover the deficit.
     * Uses existing convertStock() for AVCO-correct conversion.
     *
     * @return string|null Human-readable description of what was converted.
     */
    private function autoConvertIfNeeded(InventoryItem $target, float $requiredQty, Order $order, User $user): ?string
    {
        $source = InventoryItem::where('id', $target->convertible_from_id)
            ->lockForUpdate()
            ->first();

        if (! $source || (float) $source->current_quantity <= 0) {
            return null;
        }

        $deficit = $requiredQty - (float) $target->current_quantity;
        if ($deficit <= 0) {
            return null;
        }

        $ratio = (float) $target->conversion_ratio;

        // How many source units we need to cut: ceil(deficit / ratio)
        $sourceNeeded = ceil($deficit / $ratio);
        $sourceAvailable = (float) $source->current_quantity;
        $actualSourceUsed = min($sourceNeeded, $sourceAvailable);

        if ($actualSourceUsed <= 0) {
            return null;
        }

        // Use existing convertStock for proper AVCO math + append-only movements
        $this->convertStock(
            source: $source,
            target: $target,
            sourceQuantity: $actualSourceUsed,
            ratio: $ratio,
            user: $user,
            notes: "✂️ Авто-розрізка для замовлення #{$order->order_number}",
        );

        $targetProduced = $actualSourceUsed * $ratio;
        $msg = "✂️ Порізано {$actualSourceUsed} арк. {$source->name} → {$targetProduced} арк. {$target->name}";

        Log::info(
            "Auto paper conversion for order #{$order->order_number}: {$msg}"
        );

        AuditLog::record(
            eventType: 'paper_auto_conversion',
            user: $user,
            description: $msg." (замовлення #{$order->order_number})",
            shiftId: null,
            meta: [
                'source_item_id'      => $source->id,
                'source_name'         => $source->name,
                'source_qty_used'     => $actualSourceUsed,
                'target_item_id'      => $target->id,
                'target_name'         => $target->name,
                'target_qty_produced' => $targetProduced,
                'ratio'               => $ratio,
                'order_id'            => $order->id,
            ],
        );

        return $msg;
    }

    /**
     * Return inventory materials after order cancellation.
     * Uses actual movement journal data to return only what was really deducted,
     * preventing phantom stock creation when original deductions were clamped.
     */
    public function returnStockForOrder(Order $order, User $user): void
    {
        DB::transaction(function () use ($order, $user) {
            // Look up actual deductions from movement journal (source of truth)
            $movements = InventoryMovement::where('reference_type', Order::class)
                ->where('reference_id', $order->id)
                ->where('type', 'auto_deduct')
                ->get();

            foreach ($movements as $movement) {
                $returnQty = abs((float) $movement->quantity);
                if ($returnQty > 0) {
                    // The movement carries the price it went out at. It used to
                    // be looked up again from the item id, and one order can
                    // hold two deductions of the same item — two positions on
                    // the same paper — so both came back at whichever price
                    // the lookup happened to return first.
                    $this->returnStock($movement, $returnQty, $order, $user);
                }
            }
        });
    }

    /**
     * Internal method to return stock safely (append-only movement).
     * Values the return at the price of the deduction it undoes.
     */
    private function returnStock(InventoryMovement $deduction, float $quantity, Order $order, User $user): void
    {
        $item = InventoryItem::where('id', $deduction->inventory_item_id)->lockForUpdate()->firstOrFail();

        $unitCost = (float) $deduction->unit_cost;
        $returnValue = $quantity * $unitCost;

        InventoryMovement::create([
            'inventory_item_id' => $item->id,
            'type'              => 'return',
            'quantity'          => $quantity,
            'unit_cost'         => $unitCost,
            'total_cost'        => $returnValue,
            'reference_type'    => Order::class,
            'reference_id'      => $order->id,
            'user_id'           => $user->id,
            'notes'             => "Повернення матеріалів: скасування замовлення #{$order->order_number}",
        ]);

        // Recalculate AVCO: (current_value + return_value) / (current_qty + return_qty)
        $oldTotalValue = (float) $item->current_quantity * (float) $item->avg_cost;
        $newQuantity = (float) $item->current_quantity + $quantity;
        $newAvgCost = $newQuantity > 0 ? ($oldTotalValue + $returnValue) / $newQuantity : 0;

        $item->update([
            'current_quantity' => $newQuantity,
            'avg_cost'         => round($newAvgCost, 4),
        ]);
    }

    /**
     * Convert stock from one inventory item to another (e.g. cut A3 → A4).
     *
     * Creates paired append-only movements:
     *   - convert_out on source (negative qty)
     *   - convert_in  on target (positive qty, AVCO recalculated)
     *
     * @param  float  $ratio  How many target units per 1 source unit (e.g. 2 for A3→A4)
     */
    public function convertStock(
        InventoryItem $source,
        InventoryItem $target,
        float $sourceQuantity,
        float $ratio,
        User $user,
        ?string $notes = null,
    ): void {
        DB::transaction(function () use ($source, $target, $sourceQuantity, $ratio, $user, $notes) {
            // A ratio of zero writes the source off and produces nothing: the
            // value of what was cut simply disappears. Both callers happen to
            // guard it today — deductStock() by condition, the admin form by
            // validation — but this is the method that does the damage, and it
            // is public.
            if ($ratio <= 0) {
                throw new \InvalidArgumentException(
                    "Коефіцієнт конвертації має бути більшим за 0 (отримано: {$ratio})."
                );
            }

            if ($sourceQuantity <= 0) {
                throw new \InvalidArgumentException(
                    "Кількість для конвертації має бути більшою за 0 (отримано: {$sourceQuantity})."
                );
            }

            // Lock both items to prevent concurrent modifications
            $source = InventoryItem::where('id', $source->id)->lockForUpdate()->firstOrFail();
            $target = InventoryItem::where('id', $target->id)->lockForUpdate()->firstOrFail();

            // Validate sufficient stock
            if ($source->current_quantity < $sourceQuantity) {
                throw new \InvalidArgumentException(
                    "Недостатньо '{$source->name}' на складі: є ".self::countForHumans($source->current_quantity).', потрібно '.self::countForHumans($sourceQuantity).'.'
                );
            }

            $targetQuantity = $sourceQuantity * $ratio;
            $totalCost = $sourceQuantity * (float) $source->avg_cost;

            // 1. Deduct from source
            InventoryMovement::create([
                'inventory_item_id' => $source->id,
                'type'              => 'convert_out',
                'quantity'          => -$sourceQuantity,
                'unit_cost'         => $source->avg_cost,
                'total_cost'        => $totalCost,
                'user_id'           => $user->id,
                'notes'             => $notes ?? "Конвертація → {$target->name}",
            ]);
            $source->decrement('current_quantity', $sourceQuantity);

            // 2. Add to target with AVCO recalculation
            $unitCost = $targetQuantity > 0 ? $totalCost / $targetQuantity : 0;

            InventoryMovement::create([
                'inventory_item_id' => $target->id,
                'type'              => 'convert_in',
                'quantity'          => $targetQuantity,
                'unit_cost'         => round($unitCost, 4),
                'total_cost'        => $totalCost,
                'user_id'           => $user->id,
                'notes'             => $notes ?? "Конвертація ← {$source->name}",
            ]);

            // AVCO: (old_value + incoming_value) / (old_qty + incoming_qty)
            $oldTotalValue = (float) $target->current_quantity * (float) $target->avg_cost;
            $newQuantity = (float) $target->current_quantity + $targetQuantity;
            $newAvgCost = $newQuantity > 0 ? ($oldTotalValue + $totalCost) / $newQuantity : 0;

            $target->update([
                'current_quantity' => $newQuantity,
                'avg_cost'         => round($newAvgCost, 4),
            ]);

            // Trigger cost_markup recalc on Constructor options linked to target item
            ServiceParameterOption::where('inventory_item_id', $target->id)
                ->cursor()
                ->each(fn ($opt) => $opt->save());
        });
    }

    /**
     * Install a full cartridge into a printer:
     * - Decreases current_quantity (full/refilled) by $quantity.
     * - Increases empty_quantity by $quantity.
     * - Records movement (type = 'install').
     */
    public function installToner(InventoryItem $item, float $quantity, User $user, ?string $notes = null): InventoryMovement
    {
        return DB::transaction(function () use ($item, $quantity, $user, $notes) {
            // Lock item to prevent concurrent modifications
            $item = InventoryItem::where('id', $item->id)->lockForUpdate()->firstOrFail();

            if ((float) $item->current_quantity < $quantity) {
                throw new \InvalidArgumentException(
                    "Недостатньо '{$item->name}' на складі: є ".self::countForHumans($item->current_quantity).', спроба встановити '.self::countForHumans($quantity).'.'
                );
            }

            $movement = InventoryMovement::create([
                'inventory_item_id' => $item->id,
                'type'              => 'install',
                'quantity'          => -$quantity,
                'empty_quantity'    => $quantity,
                'unit_cost'         => $item->avg_cost,
                'total_cost'        => $quantity * $item->avg_cost,
                'user_id'           => $user->id,
                'notes'             => $notes ?? 'Встановлення у пристрій',
            ]);

            $item->decrement('current_quantity', $quantity);
            $item->increment('empty_quantity', $quantity);

            return $movement;
        });
    }

    /**
     * Refill empty cartridges and return them full:
     * - Decreases empty_quantity by $quantity.
     * - Increases current_quantity by $quantity.
     * - Recalculates AVCO based on the total cost of refilling.
     * - Records movement (type = 'refill').
     */
    public function refillToner(InventoryItem $item, float $quantity, float $totalCost, User $user, ?string $notes = null): InventoryMovement
    {
        return DB::transaction(function () use ($item, $quantity, $totalCost, $user, $notes) {
            // Lock item to prevent concurrent modifications
            $item = InventoryItem::where('id', $item->id)->lockForUpdate()->firstOrFail();

            if ((float) $item->empty_quantity < $quantity) {
                throw new \InvalidArgumentException(
                    "Недостатньо порожніх картриджів '{$item->name}': є ".self::countForHumans($item->empty_quantity).', спроба заправити '.self::countForHumans($quantity).'.'
                );
            }

            $unitCost = $quantity > 0 ? $totalCost / $quantity : 0;

            $movement = InventoryMovement::create([
                'inventory_item_id' => $item->id,
                'type'              => 'refill',
                'quantity'          => $quantity,
                'empty_quantity'    => -$quantity,
                'unit_cost'         => $unitCost,
                'total_cost'        => $totalCost,
                'user_id'           => $user->id,
                'notes'             => $notes ?? 'Заправка картриджів',
            ]);

            // Calculate AVCO
            $oldTotalValue = $item->current_quantity * $item->avg_cost;
            $newTotalValue = $oldTotalValue + $totalCost;
            $newQuantity = $item->current_quantity + $quantity;
            $newAvgCost = $newQuantity > 0 ? $newTotalValue / $newQuantity : 0;

            $item->update([
                'current_quantity' => $newQuantity,
                'empty_quantity'   => $item->empty_quantity - $quantity,
                'avg_cost'         => round($newAvgCost, 4),
            ]);

            // Refresh cost_markup on Constructor options linked to this item
            ServiceParameterOption::where('inventory_item_id', $item->id)
                ->cursor()
                ->each(fn ($opt) => $opt->save());

            return $movement;
        });
    }

    /**
     * Adjust empty cartridge quantity directly:
     * - Adjusts empty_quantity by $quantityChange.
     * - Records movement (type = 'empty_adjust').
     */
    public function adjustEmptyToner(InventoryItem $item, float $quantityChange, User $user, ?string $notes = null): InventoryMovement
    {
        return DB::transaction(function () use ($item, $quantityChange, $user, $notes) {
            // Lock item to prevent concurrent modifications
            $item = InventoryItem::where('id', $item->id)->lockForUpdate()->firstOrFail();

            $newEmptyQty = (float) $item->empty_quantity + $quantityChange;
            if ($newEmptyQty < 0) {
                throw new \InvalidArgumentException(
                    'Кількість порожніх картриджів не може бути меншою за 0 (залишилось би: '.self::countForHumans($newEmptyQty).').'
                );
            }

            $movement = InventoryMovement::create([
                'inventory_item_id' => $item->id,
                'type'              => 'empty_adjust',
                'quantity'          => 0,
                'empty_quantity'    => $quantityChange,
                'unit_cost'         => 0,
                'total_cost'        => 0,
                'user_id'           => $user->id,
                'notes'             => $notes ?? 'Коригування порожніх картриджів',
            ]);

            $item->update([
                'empty_quantity' => $newEmptyQty,
            ]);

            return $movement;
        });
    }

    /**
     * Apply a physical stocktake: state both balances as counted.
     *
     * The other toner methods apply a known event — one installed, three
     * refilled — and let the balance follow. A stocktake is the reverse: the
     * shelf is the fact, and the difference from the books is what gets filed.
     *
     * Type `stocktake` is deliberately outside the consumption list in
     * ProcurementAdvisorService::consumptionRates(). A recount that landed as
     * consumption would inflate every forecast that reads it.
     *
     * @return InventoryMovement|null Null when the count agrees with the books.
     */
    public function stocktakeToner(
        InventoryItem $item,
        float $fullQuantity,
        float $emptyQuantity,
        User $user,
        ?string $notes = null,
    ): ?InventoryMovement {
        if ($fullQuantity < 0 || $emptyQuantity < 0) {
            throw new \InvalidArgumentException(
                'Кількість не може бути від\'ємною (повні: '.self::countForHumans($fullQuantity).
                ', порожні: '.self::countForHumans($emptyQuantity).').'
            );
        }

        return DB::transaction(function () use ($item, $fullQuantity, $emptyQuantity, $user, $notes) {
            // Lock item to prevent concurrent modifications
            $item = InventoryItem::where('id', $item->id)->lockForUpdate()->firstOrFail();

            $fullDelta = $fullQuantity - (float) $item->current_quantity;
            $emptyDelta = $emptyQuantity - (float) $item->empty_quantity;

            // The columns are decimal(12,4); anything under half of the last
            // digit is the same number written twice.
            if (abs($fullDelta) < 0.00005 && abs($emptyDelta) < 0.00005) {
                return null;
            }

            $movement = InventoryMovement::create([
                'inventory_item_id' => $item->id,
                'type'              => 'stocktake',
                'quantity'          => $fullDelta,
                'empty_quantity'    => $emptyDelta,
                'unit_cost'         => $item->avg_cost,
                'total_cost'        => abs($fullDelta) * (float) $item->avg_cost,
                'user_id'           => $user->id,
                'notes'             => $notes ?? 'Інвентаризація',
            ]);

            // avg_cost is untouched on purpose: a recount restates how many,
            // never how much they cost. Nothing downstream needs the
            // cost_markup refresh the receipt paths do for that reason.
            $item->update([
                'current_quantity' => $fullQuantity,
                'empty_quantity'   => $emptyQuantity,
            ]);

            return $movement;
        });
    }

    /**
     * A quantity as a person counts it, for a sentence a person reads.
     *
     * These columns are `decimal(*, 4)`, so `{$item->empty_quantity}` in a
     * string interpolates «7.0000». Nobody has seven-point-zero-zero-zero-zero
     * cartridges. Seen on production 2026-08-03, in the refusal round 29 had
     * just moved under the quantity field — «є 7.0000, спроба заправити 9999»
     *.
     *
     * Paper is measured in sheets and toner in pieces, but conversions leave
     * halves behind, so fractions are kept when there are any: `7.5` stays
     * `7,5`, `7.0000` becomes `7`.
     */
    private static function countForHumans(float|string $quantity): string
    {
        $number = (float) $quantity;

        // A dot, not a comma: `deductStock()` has printed shortfalls this way
        // since round 6 («потрібно 12.5, на складі 3»), and one screen showing
        // two decimal separators would be worse than either choice.
        return (string) round($number, 4);
    }
}
