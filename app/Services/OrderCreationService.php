<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * OrderCreationService
 *
 * Encapsulates the full order creation pipeline:
 *   1. Generate unique order number (advisory lock)
 *   2. Build order items with price snapshots (Constructor + Riso)
 *   3. Ask LimitService about the saved order (quotas, categories, the journal)
 *
 * Saving the cost centre into the department reference used to be step 2 here,
 * and in four other writers besides. It is `OrderObserver`'s now — see the
 * docblock there for why the ordering it used to have was wrong.
 *
 * Extracted from OrderController::store() for SRP and testability.
 *
 * Step 4 used to be a private method here, which is why for sixteen rounds it
 * ran on creation and nowhere else — `OrderController::update()` rebuilt every
 * item of an order and asked no rule anything. It now lives in LimitService,
 * where both paths can reach it.
 */
class OrderCreationService
{
    public function __construct(
        private readonly OrderNumberService $orderNumberService,
        private readonly OrderItemBuilder $itemBuilder,
        private readonly LimitService $limitService,
    ) {}

    /**
     * What the operator has to be told about the order that was just saved.
     *
     * Held here rather than flashed from inside the transaction: the rules that
     * produce these all save the order anyway, so the message belongs to the
     * response, not to the write. Read it after createOrder().
     *
     * @var array<int, string>
     */
    private array $warnings = [];

    /** @return array<int, string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Create a new order with all its items inside a DB transaction.
     *
     * @param  array<string, mixed>  $data  Validated request data
     * @param  Shift  $shift  Current open shift
     * @param  User  $user  Authenticated user
     * @return Order The created order with items
     */
    public function createOrder(array $data, Shift $shift, User $user): Order
    {
        $type = OrderType::from($data['type']);

        return DB::transaction(function () use ($data, $shift, $user, $type) {
            $numberData = $this->orderNumberService->generateForNow($type);

            $order = Order::create([
                ...$numberData,
                'type'              => $type->value,
                'status'            => OrderStatus::New->value,
                'user_id'           => $user->id,
                'shift_id'          => $shift->id,
                'authorized_person' => $data['authorized_person'] ?? null,
                'initiator'         => $data['initiator'] ?? null,
                'cost_center'       => $data['cost_center'] ?? null,
                // Filtered by type, exactly as `OrderController::update()` has
                // always filtered it. Without the left-hand side an internal
                // order could be saved flagged «По собівартості» — a label that
                // means nothing where there is no commercial price to charge
                // instead of, and one the form hides rather than refuses. That
                // is R17-1's shape pointing the other way.
                'is_at_cost'       => $type === OrderType::Commercial && ($data['is_at_cost'] ?? false),
                'total_commercial' => 0,
                'total_cost'       => 0,
            ]);

            [$totalCommercial, $totalCost] = $this->itemBuilder->buildItems($order, $data['items']);

            $order->update([
                'total_commercial' => $totalCommercial,
                'total_cost'       => $totalCost,
            ]);

            $this->warnings = $this->limitService->recordForOrder($order, $user, $shift);

            // Invalidate dashboard chart cache so new data is visible immediately
            Cache::forget('dashboard:charts');

            return $order;
        });
    }
}
