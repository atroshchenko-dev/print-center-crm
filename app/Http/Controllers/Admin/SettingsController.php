<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateNumericSettingRequest;
use App\Models\Setting;
use App\Services\ReferenceDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SettingsController
 *
 * Admin-only system settings.
 * Settings stored in DB `settings` table (key-value) with Redis caching.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly ReferenceDataService $referenceData,
    ) {}

    /**
     * Default values for all configurable settings.
     * Used as fallbacks when DB has no value yet.
     */
    private const DEFAULTS = [
        'cache_enabled'              => true,
        'payment_methods_enabled'    => 'cash',
        'telegram_enabled'           => true,
        'large_order_threshold'      => 500,
        'cash_discrepancy_threshold' => 50,
        'riso_commercial_markup'     => 2.0,
        'approval_ttl_hours'         => 72,
    ];

    public function index(): Response
    {
        $settings = Setting::getAllCached();

        // Parse payment methods into array for frontend
        $enabledMethods = array_filter(
            explode(',', $settings['payment_methods_enabled'] ?? 'cash')
        );

        return Inertia::render('Admin/Settings', [
            'settings'       => $settings,
            'defaults'       => self::DEFAULTS,
            'paymentMethods' => [
                ['key' => 'cash', 'label' => 'Готівка', 'enabled' => in_array('cash', $enabledMethods)],
                ['key' => 'card', 'label' => 'Картка', 'enabled' => in_array('card', $enabledMethods)],
            ],
        ]);
    }

    /**
     * Toggle cache on/off.
     */
    public function toggleCache(): RedirectResponse
    {
        $current = Setting::getValue('cache_enabled', true);
        Setting::setValue('cache_enabled', ! $current);

        // Flush cached reference data when toggling. Through the service, not
        // a second copy of its key list: the last time invalidation knowledge
        // lived at the call sites, half of them fell behind.
        $this->referenceData->flush();
        Cache::forget('dashboard:charts');

        $status = ! $current ? 'увімкнено' : 'вимкнено';

        return back()->with('success', "Кешування довідників {$status}.");
    }

    /**
     * Toggle a payment method on/off.
     */
    public function togglePaymentMethod(string $method): RedirectResponse
    {
        $allowed = ['cash', 'card'];

        if (! in_array($method, $allowed, true)) {
            return back()->with('error', 'Невідомий метод оплати.');
        }

        $current = array_filter(
            explode(',', Setting::getValue('payment_methods_enabled', 'cash') ?: 'cash')
        );

        if (in_array($method, $current, true)) {
            // Disable — but at least one method must remain
            $updated = array_values(array_diff($current, [$method]));
            if (empty($updated)) {
                return back()->with('error', 'Повинен залишитись хоча б один спосіб оплати.');
            }
        } else {
            // Enable
            $updated = array_merge($current, [$method]);
        }

        Setting::setValue('payment_methods_enabled', implode(',', $updated));

        $label = $method === 'cash' ? 'Готівка' : 'Картка';
        $state = in_array($method, $updated, true) ? 'увімкнено' : 'вимкнено';

        return back()->with('success', "{$label} — {$state}.");
    }

    /**
     * Toggle Telegram notifications on/off.
     */
    public function toggleTelegram(): RedirectResponse
    {
        $current = Setting::getValue('telegram_enabled', true);
        Setting::setValue('telegram_enabled', ! $current);

        $status = ! $current ? 'увімкнено' : 'вимкнено';

        return back()->with('success', "Telegram-сповіщення {$status}.");
    }

    /**
     * Update a numeric setting value.
     * Used for thresholds, markup, TTL.
     */
    public function updateNumericSetting(UpdateNumericSettingRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $labels = [
            'large_order_threshold'      => 'Поріг великого замовлення',
            'cash_discrepancy_threshold' => 'Поріг розбіжності каси',
            'riso_commercial_markup'     => 'Рисо — комерційна націнка',
            'approval_ttl_hours'         => 'TTL посилання погодження',
        ];

        $label = $labels[$data['key']] ?? $data['key'];

        // Per-key minimum constraints (markup cannot be 0, TTL cannot be 0).
        //
        // Round 29 turned this from a flash into a validation error keyed on
        // `value`. It is a rule about a field, and as a flash it arrived as a
        // toast that says «Значення не може бути менше 1» — on a page carrying
        // four fields all called «значення» — and then disappeared after twelve
        // seconds. Keyed, it lands under the card it is about and stays there,
        // and it names which setting rather than trusting the reader to
        // remember which button they pressed.
        $minValues = [
            'riso_commercial_markup' => 1,
            'approval_ttl_hours'     => 1,
        ];

        if (isset($minValues[$data['key']]) && (float) $data['value'] < $minValues[$data['key']]) {
            throw ValidationException::withMessages([
                'value' => "«{$label}» не може бути менше {$minValues[$data['key']]}.",
            ]);
        }

        Setting::setValue($data['key'], (string) $data['value']);

        return back()->with('success', "{$label} оновлено: {$data['value']}");
    }
}
