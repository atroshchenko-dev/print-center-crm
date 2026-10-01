<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * inventory:forecast divides consumption by --period to get a daily rate.
 * Nothing checked the option, so --period=0 crashed on a division by zero.
 *
 * The forecast must also see what the deduction path sees: an item backed by
 * a convertible source (A4 cut from A3) does not deplete while the source
 * holds, and the source itself is consumed by `convert_out` movements that
 * carry no `auto_deduct` at all.
 */
class InventoryForecastCommandTest extends TestCase
{
    use RefreshDatabase;

    private function itemWithConsumption(float $stock, float $used): InventoryItem
    {
        $item = $this->item($stock);

        $this->movement($item, 'auto_deduct', -$used);

        return $item;
    }

    private function item(float $stock): InventoryItem
    {
        return InventoryItem::factory()->create([
            'inventory_category_id' => InventoryCategory::factory()->create()->id,
            'is_active'             => true,
            'current_quantity'      => $stock,
        ]);
    }

    private function movement(InventoryItem $item, string $type, float $quantity): void
    {
        InventoryMovement::create([
            'inventory_item_id' => $item->id,
            'type'              => $type,
            'quantity'          => $quantity,
            'user_id'           => User::factory()->create()->id,
        ]);
    }

    /** Attach a conversion source (1 source sheet = 2 target sheets). */
    private function sourceFor(InventoryItem $target, float $stock): InventoryItem
    {
        $source = $this->item($stock);

        $target->update([
            'convertible_from_id' => $source->id,
            'conversion_ratio'    => 2.00,
        ]);

        return $source;
    }

    public function test_a_zero_lookback_is_refused_instead_of_dividing_by_it(): void
    {
        $this->itemWithConsumption(stock: 100, used: 60);

        $this->artisan('inventory:forecast', ['--period' => 0])
            ->expectsOutputToContain('--period must be at least 1 day.')
            ->assertExitCode(1);
    }

    public function test_a_negative_lookback_is_refused_too(): void
    {
        $this->artisan('inventory:forecast', ['--period' => -5])
            ->assertExitCode(1);
    }

    /**
     * 60 units over 30 days is 2 a day; 100 in stock is 50 days of cover,
     * which is well clear of a 3-day alarm.
     */
    public function test_a_well_stocked_item_raises_no_alarm(): void
    {
        $this->itemWithConsumption(stock: 100, used: 60);

        $this->artisan('inventory:forecast', ['--period' => 30, '--days' => 3])
            ->expectsOutputToContain('All items are above the depletion threshold')
            ->assertExitCode(0);
    }

    /**
     * 60 over 30 days is 2 a day against 4 in stock — two days left.
     */
    public function test_an_item_about_to_run_out_is_reported(): void
    {
        $this->itemWithConsumption(stock: 4, used: 60);

        $this->artisan('inventory:forecast', ['--period' => 30, '--days' => 3])
            ->expectsOutputToContain('will deplete within')
            ->assertExitCode(0);
    }

    /**
     * The user's screenshot case: one A4 sheet left, two a day used — but a
     * hundred A3 on the shelf, and the deduction path cuts A3 into A4 on its
     * own. 1 + 100×2 = 201 sheets is 100 days of cover, not «⛔ сьогодні».
     */
    public function test_a_depleted_item_covered_by_its_source_raises_no_alarm(): void
    {
        $item = $this->itemWithConsumption(stock: 1, used: 60);
        $this->sourceFor($item, stock: 100);

        $this->artisan('inventory:forecast', ['--period' => 30, '--days' => 3])
            ->expectsOutputToContain('All items are above the depletion threshold')
            ->assertExitCode(0);
    }

    /**
     * A reserve too small to lift the item over the threshold still alerts,
     * and the message says what the days figure already includes: 1 own sheet
     * plus 2 source sheets ×2 is 5, at 2 a day — two days, reserve named.
     */
    public function test_a_reserve_too_small_to_cover_is_named_in_the_alert(): void
    {
        Config::set('services.telegram.bot_token', 'test-token-123');
        Config::set('services.telegram.chat_id', '12345678');
        Cache::forget('app:settings');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $item = $this->itemWithConsumption(stock: 1, used: 60);
        $source = $this->sourceFor($item, stock: 2);

        $this->artisan('inventory:forecast', ['--period' => 30, '--days' => 3])
            ->expectsOutputToContain('will deplete within')
            ->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request['text'], "+2 арк. «{$source->name}»")
            && str_contains($request['text'], '⏳ 2 дн.'));
    }

    /**
     * The source side of the same blind spot: cutting A3 for A4 writes
     * `convert_out`, not `auto_deduct`, so the item actually being drained
     * never appeared in the forecast at all.
     */
    public function test_a_source_drained_by_cutting_is_forecast_too(): void
    {
        $this->movement($this->item(stock: 4), 'convert_out', -60);

        $this->artisan('inventory:forecast', ['--period' => 30, '--days' => 3])
            ->expectsOutputToContain('will deplete within')
            ->assertExitCode(0);
    }

    /**
     * Sheets arriving from a cut are stock, not consumption — `convert_in`
     * must stay outside the daily-rate sum.
     */
    public function test_incoming_conversions_are_not_consumption(): void
    {
        $this->movement($this->item(stock: 4), 'convert_in', 60);

        $this->artisan('inventory:forecast', ['--period' => 30, '--days' => 3])
            ->expectsOutputToContain('No consumption data found')
            ->assertExitCode(0);
    }

    /**
     * A soft-deleted source is no reserve: the deduction path will not find
     * it either, so the forecast must not count its sheets as cover.
     */
    public function test_a_soft_deleted_source_gives_no_cover(): void
    {
        $item = $this->itemWithConsumption(stock: 1, used: 60);
        $this->sourceFor($item, stock: 100)->delete();

        $this->artisan('inventory:forecast', ['--period' => 30, '--days' => 3])
            ->expectsOutputToContain('will deplete within')
            ->assertExitCode(0);
    }
}
