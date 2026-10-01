<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderType;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * RequestSourceTest — чим підтверджена заявка: папером чи листом.
 *
 * Обидва шляхи виставляють той самий `request_received`, тож джерело
 * виводиться з `order_approvals`. Тут закріплено, що воно виводиться
 * саме зі статусу `approved`, а не з будь-якого надісланого листа.
 */
class RequestSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(); // Telegram notifications must not reach the network
    }

    /**
     * An operator with an open shift — orders.request sits behind
     * EnsureShiftIsOpen, so without one the toggle never reaches the
     * controller and the test would pass for the wrong reason.
     *
     * OrderFactory opens a shift itself when none is running, so the guard
     * below is not decoration: calling openShift() after a factory order
     * dies with «A shift is already open».
     */
    private function operator(): User
    {
        $user = User::factory()->create(['role' => 'executor']);

        if (! Shift::current()->exists()) {
            app(ShiftService::class)->openShift($user, [
                ['equipment_id' => Equipment::factory()->create(['type' => 'bw', 'is_active' => true])->id, 'counter_value' => 10_000],
            ]);
        }

        return $user;
    }

    private function approval(Order $order, string $status): OrderApproval
    {
        return OrderApproval::create([
            'order_id'        => $order->id,
            'token'           => bin2hex(random_bytes(24)),
            'signatory_email' => 'signatory@example.test',
            'signatory_name'  => 'Тестовий Підписант',
            'status'          => $status,
            'expires_at'      => now()->addHours(72),
        ]);
    }

    public function test_the_flag_is_true_only_for_an_approved_letter(): void
    {
        $approved = Order::factory()->create(['request_received' => true]);
        $pending = Order::factory()->create(['request_received' => true]);
        $rejected = Order::factory()->create(['request_received' => true]);
        $paper = Order::factory()->create(['request_received' => true]);

        $this->approval($approved, 'approved');
        $this->approval($pending, 'pending');
        $this->approval($rejected, 'rejected');

        $flags = Order::withEmailApprovalFlag()->pluck('has_email_approval', 'id');

        $this->assertTrue((bool) $flags[$approved->id]);
        $this->assertFalse((bool) $flags[$pending->id]);
        $this->assertFalse((bool) $flags[$rejected->id]);
        $this->assertFalse((bool) $flags[$paper->id]);
    }

    public function test_the_flag_does_not_carry_the_signatory_email(): void
    {
        $order = Order::factory()->create(['request_received' => true]);
        $this->approval($order, 'approved');

        $loaded = Order::withEmailApprovalFlag()->find($order->id);

        $this->assertArrayNotHasKey('signatory_email', $loaded->toArray());
        $this->assertFalse($loaded->relationLoaded('approvals'));
    }

    public function test_the_filter_separates_paper_from_email(): void
    {
        $email = Order::factory()->create(['request_received' => true]);
        $paper = Order::factory()->create(['request_received' => true]);
        $none = Order::factory()->create(['request_received' => false]);

        $this->approval($email, 'approved');

        $this->assertEquals(
            [$email->id],
            Order::filterRequestSource('email')->pluck('id')->all(),
        );

        $this->assertEquals(
            [$paper->id],
            Order::filterRequestSource('paper')->pluck('id')->all(),
        );

        $this->assertEqualsCanonicalizing(
            [$email->id, $paper->id],
            Order::filterRequestSource('1')->pluck('id')->all(),
        );

        $this->assertEquals(
            [$none->id],
            Order::filterRequestSource('0')->pluck('id')->all(),
        );

        $this->assertCount(3, Order::filterRequestSource('')->get());
        $this->assertCount(3, Order::filterRequestSource(null)->get());
    }

    public function test_the_order_screen_refuses_to_unset_a_letter_approved_request(): void
    {
        $order = Order::factory()->create([
            'type'             => OrderType::Internal,
            'request_received' => true,
        ]);
        $this->approval($order, 'approved');

        $this->actingAs($this->operator())
            ->patch(route('orders.request', $order), ['request_received' => false])
            ->assertSessionHas('error');

        $this->assertTrue($order->fresh()->request_received);
    }

    public function test_the_reconciliation_screen_refuses_the_same(): void
    {
        $order = Order::factory()->create([
            'type'             => OrderType::Internal,
            'request_received' => true,
        ]);
        $this->approval($order, 'approved');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch(route('admin.reconciliation.toggle-request', $order), ['request_received' => false])
            ->assertSessionHas('error');

        $this->assertTrue($order->fresh()->request_received);
    }

    public function test_the_backdated_screen_refuses_the_same(): void
    {
        $order = Order::factory()->create([
            'type'             => OrderType::Internal,
            'is_backdated'     => true,
            'request_received' => true,
        ]);
        $this->approval($order, 'approved');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch(route('admin.backdated-orders.toggle-request', $order), ['request_received' => false])
            ->assertSessionHas('error');

        $this->assertTrue($order->fresh()->request_received);
    }

    public function test_the_reconciliation_filter_can_ask_for_paper_only(): void
    {
        $email = Order::factory()->create([
            'type'             => OrderType::Internal,
            'request_received' => true,
        ]);
        $paper = Order::factory()->create([
            'type'             => OrderType::Internal,
            'request_received' => true,
        ]);

        $this->approval($email, 'approved');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.reconciliation.index', ['request_received' => 'paper']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.total', 1)
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $paper->id));
    }

    public function test_a_paper_request_can_still_be_unset(): void
    {
        $order = Order::factory()->create([
            'type'             => OrderType::Internal,
            'request_received' => true,
        ]);

        $this->actingAs($this->operator())
            ->patch(route('orders.request', $order), ['request_received' => false])
            ->assertSessionHas('success');

        $this->assertFalse($order->fresh()->request_received);
    }
}
