<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * ReconciliationWorkflowTest — admin reconciliation of internal orders.
 *
 * TZ §5: admin verifies internal orders against paper requests.
 */
class ReconciliationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /**
     * The same off-by-one the report exports were fixed for, one controller
     * over. dateRange() hands back Kyiv midnights as UTC instants; formatting
     * one straight into the file name drops the start of the range onto the
     * previous date, so a month closed from 1 July arrived on the accountant's
     * disk as `reconciliation-20260630-…`. The rows inside are selected from the
     * same bounds and are right, which is what kept it quiet.
     */
    public function test_the_export_is_named_after_the_kyiv_dates_that_were_asked_for(): void
    {
        Excel::fake();

        $this->actingAs($this->admin)
            ->get(route('admin.reconciliation.export', ['from' => '2026-07-01', 'to' => '2026-07-31']))
            ->assertOk();

        Excel::assertDownloaded('reconciliation-20260701-20260731.xlsx');
    }

    public function test_the_export_without_a_range_is_named_all(): void
    {
        Excel::fake();

        $this->actingAs($this->admin)
            ->get(route('admin.reconciliation.export'))
            ->assertOk();

        Excel::assertDownloaded('reconciliation-all.xlsx');
    }

    public function test_admin_can_reconcile_internal_order(): void
    {
        $order = Order::factory()->internal()->withStatus(OrderStatus::CompletedIssued)->create();

        $response = $this->actingAs($this->admin)
            ->patch(route('admin.reconciliation.reconcile', $order));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $order->refresh();
        $this->assertTrue($order->is_reconciled);
        $this->assertNotNull($order->reconciled_at);
        $this->assertEquals($this->admin->id, $order->reconciled_by);
    }

    public function test_batch_reconciliation(): void
    {
        $orders = Order::factory()->internal()
            ->withStatus(OrderStatus::CompletedIssued)
            ->count(3)
            ->create();

        $response = $this->actingAs($this->admin)
            ->post(route('admin.reconciliation.batch'), [
                'order_ids' => $orders->pluck('id')->all(),
            ]);

        $response->assertRedirect();

        foreach ($orders as $order) {
            $order->refresh();
            $this->assertTrue($order->is_reconciled);
        }
    }

    public function test_admin_can_unreconcile_order(): void
    {
        $order = Order::factory()->internal()
            ->withStatus(OrderStatus::CompletedIssued)
            ->create([
                'is_reconciled' => true,
                'reconciled_at' => now(),
                'reconciled_by' => $this->admin->id,
            ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.reconciliation.unreconcile', $order));

        $response->assertRedirect();

        $order->refresh();
        $this->assertFalse($order->is_reconciled);
        $this->assertNull($order->reconciled_at);
    }

    public function test_cannot_reconcile_commercial_order(): void
    {
        $order = Order::factory()->commercial()
            ->withStatus(OrderStatus::PaidIssued)
            ->create();

        $response = $this->actingAs($this->admin)
            ->patch(route('admin.reconciliation.reconcile', $order));

        $response->assertSessionHas('error');
    }

    // ─── Closing the month against what the screen was showing ───

    /**
     * The paper request is what this screen is for (ТЗ §5), and "Заявка" is one
     * of its filters. Closing the month used to run a second query that knew
     * about four filters out of six, so an accountant who narrowed the list to
     * the orders whose request had arrived was shown "Буде звірено 1" — the
     * count comes from the filtered summary — and reconciled the ones without a
     * request too.
     */
    public function test_closing_the_month_respects_the_request_filter_the_screen_applied(): void
    {
        $withRequest = Order::factory()->internal()->withStatus(OrderStatus::CompletedIssued)
            ->create(['request_received' => true, 'is_reconciled' => false]);
        $withoutRequest = Order::factory()->internal()->withStatus(OrderStatus::CompletedIssued)
            ->create(['request_received' => false, 'is_reconciled' => false]);

        $this->actingAs($this->admin)
            ->post(route('admin.reconciliation.close-month'), ['request_received' => true])
            ->assertRedirect();

        $this->assertTrue($withRequest->fresh()->is_reconciled);
        $this->assertFalse(
            $withoutRequest->fresh()->is_reconciled,
            'An order with no paper request was closed by a screen that was not showing it.',
        );
    }

    /**
     * Filtered to the already-reconciled orders, the dialog says "Буде звірено
     * 0". It has to mean it.
     */
    public function test_closing_the_month_filtered_to_reconciled_touches_nothing(): void
    {
        $open = Order::factory()->internal()->withStatus(OrderStatus::CompletedIssued)
            ->create(['is_reconciled' => false]);

        $this->actingAs($this->admin)
            ->post(route('admin.reconciliation.close-month'), ['reconciled' => 'true'])
            ->assertRedirect();

        $this->assertFalse($open->fresh()->is_reconciled);
    }

    public function test_closing_the_month_unfiltered_still_closes_the_month(): void
    {
        $orders = Order::factory()->count(3)->internal()->withStatus(OrderStatus::CompletedIssued)
            ->create(['is_reconciled' => false]);

        $this->actingAs($this->admin)
            ->post(route('admin.reconciliation.close-month'))
            ->assertRedirect();

        foreach ($orders as $order) {
            $this->assertTrue($order->fresh()->is_reconciled);
        }
    }
}
