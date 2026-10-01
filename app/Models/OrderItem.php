<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * OrderItem Model
 *
 * Represents a single line item in an order.
 * The `service_snapshot` JSONB column stores the full Constructor configuration
 * as an immutable snapshot (see ТЗ Додаток А.5).
 *
 * Uses array casting for JSONB → PHP array conversion.
 * Join tables for constructor options are STRICTLY FORBIDDEN.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $service_id
 * @property array $service_snapshot
 * @property string $service_name
 * @property string|null $material_description
 * @property int $quantity
 * @property float $unit_price_commercial
 * @property float $unit_price_cost
 * @property float $total_price_commercial
 * @property float $total_price_cost
 * @property int $bw_clicks
 * @property int $color_clicks
 * @property int $riso_clicks
 */
class OrderItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'order_id',
        'service_id',
        'service_snapshot',
        'service_name',
        'material_description',
        'quantity',
        'unit_price_commercial',
        'unit_price_cost',
        'total_price_commercial',
        'total_price_cost',
        'bw_clicks',
        'color_clicks',
        'riso_clicks',
    ];

    /**
     * JSONB `service_snapshot` is cast to array.
     * This is the ONLY way to store constructor options (per ТЗ rules).
     */
    protected $casts = [
        'service_snapshot'       => 'array',
        'quantity'               => 'integer',
        'unit_price_commercial'  => 'decimal:2',
        'unit_price_cost'        => 'decimal:2',
        'total_price_commercial' => 'decimal:2',
        'total_price_cost'       => 'decimal:2',
        'bw_clicks'              => 'integer',
        'color_clicks'           => 'integer',
        'riso_clicks'            => 'integer',
    ];

    // ─── Relationships ───────────────────────────────────

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // ─── Snapshot Accessors ──────────────────────────────

    /**
     * Get the constructor options from the JSONB snapshot.
     *
     * @return array<int, array{group_name: string, option_name: string, price_markup: float, cost_markup: float}>
     */
    public function getConstructorOptions(): array
    {
        return $this->service_snapshot['constructor_snapshot'] ?? [];
    }

    /**
     * Get hardware counter data from the JSONB snapshot.
     *
     * @return array{color_clicks: int, bw_clicks: int}
     */
    public function getHardwareCounters(): array
    {
        return $this->service_snapshot['hardware_counters'] ?? [
            'color_clicks' => 0,
            'bw_clicks'    => 0,
        ];
    }

    /**
     * Get total clicks across all counter types for this item.
     */
    public function getTotalClicks(): int
    {
        return $this->bw_clicks + $this->color_clicks + $this->riso_clicks;
    }

    // ─── Retention (docs/PII.md §3) ──────────────────────

    /**
     * Business-card lines whose customer name is past its retention window.
     *
     * Owner's decision, 2026-07-31, on a question open since round 2: the name
     * an operator types into `material_description` for a business-card order
     * is treated like an approval signatory's — anonymised after the window,
     * not deleted. The line, its quantity and its money stay; who the cards
     * were for stops being answerable.
     *
     * Scoped to «Візитівки» alone. The same field on other items holds order
     * content — «Плакати», «Дипломи 2026, 3 курс» — with no personal data in
     * it, and blanking that would destroy history for no privacy gain.
     *
     * The clock runs from `orders.created_at`: that is when the data was
     * collected, which for a retro order is not the date the work was done.
     *
     * Idempotent without a schema change: rows already carrying the
     * placeholder are excluded, so the placeholder is the marker that
     * `order_approvals` keeps in `anonymized_at`. That avoids a migration on a
     * live table for a nightly cleanup.
     */
    public function scopeDuePersonalDataRemoval(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query
            ->whereNotNull('material_description')
            ->where('material_description', '!=', '')
            ->where('material_description', '!=', (string) config('privacy.placeholder'))
            ->whereHas('order', fn (Builder $order) => $order->where('created_at', '<', $cutoff))
            // Both links resolved **including retired rows**. `Service` and
            // `ServiceCategory` are soft-deleted, and `whereHas` honours the
            // global scope — so retiring a service used to drop every line ever
            // priced from it out of this sweep, silently, while the category sat
            // in place keeping the command's warning quiet. The customer's name
            // then outlived its retention window with nothing anywhere saying
            // so.
            //
            // The name was collected under the retention policy, not under the
            // service. Same shape as R18-2 and R19-1 — a rule holding its
            // subject by something removable — except what leaks here is not
            // money.
            ->whereHas('service', fn (Builder $service) => $service
                ->withTrashed()
                ->whereHas('category', fn (Builder $category) => $category
                    ->withTrashed()
                    ->where('name', ServiceCategory::BUSINESS_CARDS)));
    }

    /**
     * The service this line was priced from — needed to know its category.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Drop the customer's name, keep the line.
     */
    public function anonymizeDescription(): void
    {
        $this->forceFill([
            'material_description' => (string) config('privacy.placeholder'),
        ])->save();
    }
}
