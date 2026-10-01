<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The settings screen at 13%: seven switches and numbers, every one of which
 * something else in the system reads — the Telegram thresholds, the Riso
 * commercial markup, the approval link's TTL, reference-data caching, and which
 * payment methods the till may take.
 *
 * Checked while writing these: all seven have a reader outside this controller.
 * A switch that changes nothing is the failure this screen is most exposed to,
 * and it is not the failure it has.
 */
class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role'        => 'admin',
            'permissions' => User::permissionKeys(),
        ]);
    }

    public function test_the_page_reports_which_payment_methods_are_on(): void
    {
        Setting::setValue('payment_methods_enabled', 'cash,card');

        $props = $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertTrue(collect($props['paymentMethods'])->firstWhere('key', 'cash')['enabled']);
        $this->assertTrue(collect($props['paymentMethods'])->firstWhere('key', 'card')['enabled']);
    }

    public function test_the_page_is_closed_to_a_non_admin(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'permissions' => ['reports', 'orders']]);

        $this->actingAs($manager)
            ->get(route('admin.settings.index'))
            ->assertForbidden();
    }

    // ─── Cache ───────────────────────────────────────────

    public function test_the_cache_switch_flips_and_says_which_way(): void
    {
        Setting::setValue('cache_enabled', true);

        $this->actingAs($this->admin)
            ->post(route('admin.settings.toggle-cache'))
            ->assertSessionHas('success', 'Кешування довідників вимкнено.');

        $this->assertFalse(Setting::getValue('cache_enabled'));

        $this->actingAs($this->admin)
            ->post(route('admin.settings.toggle-cache'))
            ->assertSessionHas('success', 'Кешування довідників увімкнено.');

        $this->assertTrue(Setting::getValue('cache_enabled'));
    }

    // ─── Payment methods ─────────────────────────────────

    public function test_a_payment_method_can_be_switched_on_and_off(): void
    {
        Setting::setValue('payment_methods_enabled', 'cash');

        $this->actingAs($this->admin)
            ->post(route('admin.settings.toggle-payment-method', 'card'))
            ->assertSessionHas('success');

        $this->assertSame('cash,card', Setting::getValue('payment_methods_enabled'));

        $this->actingAs($this->admin)
            ->post(route('admin.settings.toggle-payment-method', 'card'));

        $this->assertSame('cash', Setting::getValue('payment_methods_enabled'));
    }

    /**
     * A till that takes nothing cannot close a commercial order at all — the
     * status transition to paid_issued asks for a method and there would be
     * none to offer.
     */
    public function test_the_last_payment_method_cannot_be_switched_off(): void
    {
        Setting::setValue('payment_methods_enabled', 'cash');

        $this->actingAs($this->admin)
            ->post(route('admin.settings.toggle-payment-method', 'cash'))
            ->assertSessionHas('error', 'Повинен залишитись хоча б один спосіб оплати.');

        $this->assertSame('cash', Setting::getValue('payment_methods_enabled'));
    }

    public function test_an_unknown_payment_method_is_refused(): void
    {
        Setting::setValue('payment_methods_enabled', 'cash');

        $this->actingAs($this->admin)
            ->post(route('admin.settings.toggle-payment-method', 'crypto'))
            ->assertSessionHas('error', 'Невідомий метод оплати.');

        $this->assertSame('cash', Setting::getValue('payment_methods_enabled'));
    }

    // ─── Telegram ────────────────────────────────────────

    public function test_the_telegram_switch_flips(): void
    {
        Setting::setValue('telegram_enabled', true);

        $this->actingAs($this->admin)->post(route('admin.settings.toggle-telegram'));

        $this->assertFalse(Setting::getValue('telegram_enabled'));
    }

    // ─── Numeric settings ────────────────────────────────

    public function test_a_threshold_is_saved_as_given(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.settings.update-numeric'), [
                'key'   => 'large_order_threshold',
                'value' => 1500,
            ])
            ->assertSessionHas('success');

        $this->assertSame(1500, Setting::getValue('large_order_threshold'));
    }

    /**
     * The rules allow 0 — the per-key floors live in the controller. A markup
     * of zero would price every Riso job at nothing, and a TTL of zero would
     * mint approval links that are dead on arrival.
     */
    public function test_the_markup_cannot_be_dropped_below_one(): void
    {
        Setting::setValue('riso_commercial_markup', 2);

        $this->actingAs($this->admin)
            ->post(route('admin.settings.update-numeric'), [
                'key'   => 'riso_commercial_markup',
                'value' => 0,
            ])
            ->assertSessionHasErrors('value');

        $this->assertSame(2, Setting::getValue('riso_commercial_markup'));
    }

    /**
     * The floor names the setting, because the page cannot.
     *
     * Four numeric settings, four forms, one field each — and every one of
     * them is «значення». «Значення не може бути менше 1» was true and
     * useless: it named a field this page has four of, in a toast that left
     * after twelve seconds. Keyed on `value`, the message lands
     * under the card it belongs to; naming the setting is what keeps it
     * readable in the banner as well.
     */
    public function test_the_floor_says_which_setting_it_is_about(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.settings.update-numeric'), [
                'key'   => 'approval_ttl_hours',
                'value' => 0,
            ])
            ->assertSessionHasErrors('value');

        $this->assertStringContainsString(
            'TTL посилання погодження',
            (string) session('errors')->first('value'),
        );
    }

    public function test_the_approval_ttl_cannot_be_dropped_below_one_hour(): void
    {
        Setting::setValue('approval_ttl_hours', 72);

        $this->actingAs($this->admin)
            ->post(route('admin.settings.update-numeric'), [
                'key'   => 'approval_ttl_hours',
                'value' => 0,
            ])
            ->assertSessionHasErrors('value');

        $this->assertSame(72, Setting::getValue('approval_ttl_hours'));
    }

    public function test_a_key_outside_the_list_is_rejected_by_validation(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.settings.update-numeric'), [
                'key'   => 'cache_enabled',
                'value' => 0,
            ])
            ->assertSessionHasErrors('key');
    }

    public function test_a_non_admin_cannot_change_a_setting(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'permissions' => ['reports', 'orders']]);
        Setting::setValue('large_order_threshold', 500);

        $this->actingAs($manager)
            ->post(route('admin.settings.update-numeric'), [
                'key'   => 'large_order_threshold',
                'value' => 1,
            ])
            ->assertForbidden();

        $this->assertSame(500, Setting::getValue('large_order_threshold'));
    }
}
