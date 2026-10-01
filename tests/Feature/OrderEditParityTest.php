<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The boundary of round 17, asked again where it was not exhausted.
 *
 * Round 17 walked `store()` against `update()` through the quotas, the
 * reconciliation mark and the audit journal. Three answers were left standing in
 * the brief for this round, and all three are the same question: **what does
 * creation do that editing does not — and what does editing do that creation
 * does not.**
 *
 *  - the **number** carries the type in its prefix, and only creation picks it;
 *  - **`is_at_cost`** is filtered by order type on the edit path and not on the
 *    creation path, which is the asymmetry of R17-1 pointing the other way;
 *  - **money** reaches Telegram when an order is created past the threshold and
 *    never when an edit takes it there.
 */
class OrderEditParityTest extends TestCase
{
    use RefreshDatabase;

    private User $executor;

    private Shift $shift;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        Config::set('services.telegram.bot_token', 'test-token-123');
        Config::set('services.telegram.chat_id', '12345678');
        Cache::forget('app:settings');

        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00:00', 'Europe/Kyiv'));

        $this->executor = User::factory()->create(['role' => 'executor']);

        $equipment = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);
        $this->shift = app(ShiftService::class)->openShift($this->executor, [
            ['equipment_id' => $equipment->id, 'counter_value' => 10_000],
        ]);

        $category = ServiceCategory::factory()->create([
            'available_for' => ['internal', 'commercial'],
        ]);

        $this->service = Service::factory()->create([
            'service_category_id' => $category->id,
            'type' => 'static',
            'base_price_cost' => 1.00,
            'base_price_commercial' => 2.00,
            'counter_type' => 'bw',
            'clicks_per_unit' => 1,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function place(array $overrides = [])
    {
        return $this->actingAs($this->executor)->post(route('orders.store'), array_merge([
            'type' => 'internal',
            'authorized_person' => 'Іваненко Іван Іванович',
            'cost_center' => 'Кафедра тестування',
            'items' => [
                ['service_id' => $this->service->id, 'quantity' => 10],
            ],
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function edit(Order $order, array $overrides = [])
    {
        return $this->actingAs($this->executor)->put(route('orders.update', $order), array_merge([
            'type' => $order->type->value,
            'authorized_person' => $order->authorized_person,
            'cost_center' => $order->cost_center,
            'version' => $order->version,
            'items' => [
                ['service_id' => $this->service->id, 'quantity' => 10],
            ],
        ], $overrides));
    }

    // ─── The number and the type ─────────────────────────

    /**
     * `{PREFIX}-{YY}{MM}-{SEQ}` is what the paper request, the commercial export
     * and the ledger line all print. The prefix **is** the type, and the edit
     * form has a two-button switch for the type — so an order can end up
     * commercial while every screen still calls it INT.
     *
     * The retro module already decided this shape: it renumbers when an edit
     * moves an order into another month, «otherwise a July export shows a June
     * number on a July line». The live module changes the other half of the same
     * number and leaves it alone.
     */
    public function test_changing_the_type_on_an_edit_moves_the_number_with_it(): void
    {
        $this->place();
        $order = Order::latest('id')->first();

        $this->assertSame('INT', $order->order_prefix);
        $this->assertStringStartsWith('INT-', $order->order_number);

        $this->edit($order, ['type' => 'commercial', 'authorized_person' => null, 'cost_center' => null])
            ->assertRedirect();

        $order->refresh();

        $this->assertTrue($order->isCommercial());
        $this->assertSame('COM', $order->order_prefix, 'the prefix names the type of the order it is on');
        $this->assertStringStartsWith('COM-', $order->order_number);
    }

    /**
     * The month is not the type's to move. An order edited in September still
     * belongs to the August it was taken in, and its number says so.
     */
    public function test_the_renumbering_keeps_the_month_the_order_was_taken_in(): void
    {
        $this->place();
        $order = Order::latest('id')->first();

        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00', 'Europe/Kyiv'));

        $this->edit($order, ['type' => 'commercial', 'authorized_person' => null, 'cost_center' => null]);

        $order->refresh();

        $this->assertSame(26, $order->order_year);
        $this->assertSame(8, $order->order_month);
        $this->assertSame('COM-2608-001', $order->order_number);
    }

    /**
     * An edit that leaves the type alone leaves the number alone: it is what the
     * paper request is filed under.
     */
    public function test_an_edit_that_keeps_the_type_keeps_the_number(): void
    {
        $this->place();
        $order = Order::latest('id')->first();
        $number = $order->order_number;

        $this->edit($order, ['items' => [['service_id' => $this->service->id, 'quantity' => 25]]]);

        $order->refresh();

        $this->assertSame($number, $order->order_number);
        $this->assertSame(25, (int) $order->items()->sum('quantity'));
    }

    /**
     * A renumbering is a change to the identifier the accountant files by, so it
     * belongs in the journal entry the edit already writes.
     */
    public function test_the_journal_carries_both_numbers(): void
    {
        $this->place();
        $order = Order::latest('id')->first();
        $oldNumber = $order->order_number;

        $this->edit($order, ['type' => 'commercial', 'authorized_person' => null, 'cost_center' => null]);

        $order->refresh();

        $entry = AuditLog::where('event_type', 'order_edited')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($oldNumber, $entry->meta['old_number'] ?? null);
        $this->assertSame($order->order_number, $entry->meta['new_number'] ?? null);
    }

    // ─── is_at_cost, the asymmetry pointing the other way ─

    /**
     * `update()` writes `$type === Commercial && ($data['is_at_cost'] ?? false)`.
     * `store()` writes `$data['is_at_cost'] ?? false`, with no look at the type
     * at all — so an internal order can be created flagged «По собівартості»,
     * a label that means nothing on an order with no commercial price to be
     * charged instead of.
     *
     * The checkbox is hidden for internal orders on the form, which is exactly
     * what R17-1 was: a rule that lives only where the browser can see it.
     */
    public function test_an_internal_order_cannot_be_created_at_cost(): void
    {
        $this->place(['is_at_cost' => true]);

        $order = Order::latest('id')->first();

        $this->assertTrue($order->isInternal());
        $this->assertFalse($order->is_at_cost, 'the flag has no meaning on an internal order');
    }

    /** The same request on a commercial order is the feature, and it still works. */
    public function test_a_commercial_order_is_still_created_at_cost_when_asked(): void
    {
        $this->place([
            'type' => 'commercial',
            'authorized_person' => null,
            'cost_center' => null,
            'is_at_cost' => true,
        ]);

        $order = Order::latest('id')->first();

        $this->assertTrue($order->isCommercial());
        $this->assertTrue($order->is_at_cost);
    }

    // ─── What the chat is told ───────────────────────────

    /**
     * `store()` sends `largeOrder()` with the amount when the order crosses the
     * threshold. `update()` sends `orderEdited()` — order number, operator,
     * reason — and no figure at all. So an order edited from 50 ₴ to 5 000 ₴
     * puts «Замовлення відредаговано» in the chat and nothing else, while the
     * same 5 000 ₴ typed in once would have pinged.
     */
    public function test_an_edit_tells_the_chat_what_the_order_now_costs(): void
    {
        Setting::setValue('large_order_threshold', 500);

        $this->place(['items' => [['service_id' => $this->service->id, 'quantity' => 10]]]);
        $order = Order::latest('id')->first();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->edit($order, ['items' => [['service_id' => $this->service->id, 'quantity' => 5_000]]]);

        Http::assertSent(function ($request) {
            $text = $request['text'] ?? '';

            return str_contains($text, 'відредаговано')
                && str_contains($text, '5,000.00')
                && str_contains($text, '10.00');
        });
    }
}
