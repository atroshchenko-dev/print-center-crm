<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closing a shift, through the route rather than the service.
 *
 * ShiftWorkflowTest calls ShiftService::closeShift() directly, so the
 * controller was never exercised — and it redirected to /login without
 * ending the session. The guest middleware bounced the operator straight
 * back to the dashboard, the dashboard forwarded to the open-shift form,
 * and "Зміну закрито" was aged out somewhere along the way. The operator
 * pressed a button and saw nothing happen.
 */
class ShiftCloseRedirectTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create(['role' => 'executor']);
        $equipment      = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);

        $this->shift = app(ShiftService::class)->openShift($this->operator, [
            ['equipment_id' => $equipment->id, 'counter_value' => 10000],
        ]);
    }

    public function test_closing_a_shift_keeps_the_operator_signed_in(): void
    {
        $this->actingAs($this->operator)
            ->post(route('shifts.close'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->operator);
        $this->assertEquals('closed', $this->shift->fresh()->status->value);
    }

    public function test_the_confirmation_says_which_shift_and_how_much_cash(): void
    {
        $response = $this->actingAs($this->operator)->post(route('shifts.close'));

        $response->assertSessionHas('success');

        $message = session('success');
        $this->assertStringContainsString("#{$this->shift->id}", $message);
        $this->assertStringContainsString('Каса зведена', $message);
    }

    /**
     * The dashboard is a pass-through here — there is no open shift left, so
     * it forwards to the open-shift form. The message has to survive that hop,
     * which is the whole reason the operator sees anything at all.
     */
    public function test_the_confirmation_survives_the_hop_through_the_dashboard(): void
    {
        $this->actingAs($this->operator)->post(route('shifts.close'));

        // Hop 1: the dashboard has no open shift and forwards on.
        $dashboard = $this->actingAs($this->operator)->get(route('dashboard'));
        $dashboard->assertRedirect(route('shifts.open.form'));
        $dashboard->assertSessionHas('success');

        // Hop 2: the page that finally renders still has the message.
        $form = $this->actingAs($this->operator)->get(route('shifts.open.form'));
        $form->assertOk();

        $this->assertStringContainsString(
            "Зміну #{$this->shift->id} закрито",
            $form->viewData('page')['props']['flash']['success'] ?? '',
            'The operator has to actually see the confirmation.',
        );
    }
}
