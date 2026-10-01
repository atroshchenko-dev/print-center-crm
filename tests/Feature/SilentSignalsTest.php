<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Order;
use App\Notifications\OrderCreatedNotification;
use App\Providers\AppServiceProvider;
use App\Services\ShiftOpenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Three signals that went nowhere, or leaked on the way.
 *
 * Each one was written down as a rule and then quietly not enforced: a URL
 * policy applied to one channel and not another, a "sent" message for mail
 * nobody had tried to send yet, and a validation the docblock calls hard that
 * had a door left open by soft deletes.
 */
class SilentSignalsTest extends TestCase
{
    use RefreshDatabase;

    // ─── R13-5: the approval token in the slow-query log ─

    /**
     * @return list<array{string, bool}>
     */
    public static function bindingCases(): array
    {
        return [
            ['select * from "order_approvals" where "token" = ?', true],
            ['select * from "users" where "remember_token" = ?', true],
            ['update "users" set "password" = ?', true],
            ['select * from "orders" where "id" = ?', false],
            ['select count(*) from "shifts"', false],
        ];
    }

    #[DataProvider('bindingCases')]
    public function test_bindings_are_withheld_for_queries_that_carry_secrets(string $sql, bool $shouldRedact): void
    {
        $provider = new AppServiceProvider($this->app);

        $method = new \ReflectionMethod($provider, 'safeBindings');
        $result = $method->invoke($provider, $sql, ['a-real-secret-value']);

        if ($shouldRedact) {
            $this->assertIsString($result, "Bindings for [{$sql}] reach the log verbatim.");
            $this->assertStringNotContainsString('a-real-secret-value', $result);
        } else {
            $this->assertSame(['a-real-secret-value'], $result);
        }
    }

    public function test_the_slow_query_log_records_a_path_not_a_full_url(): void
    {
        // bootstrap/app.php spells out why: a full URL carries one-time approval
        // tokens and their HMAC signature. The rule was applied to the Telegram
        // channel and missed here.
        //
        // Comments are stripped first — the explanation of why fullUrl() is gone
        // names it, and an assertion that cannot tell code from prose would fail
        // on its own documentation.
        $code = php_strip_whitespace(app_path('Providers/AppServiceProvider.php'));

        $this->assertStringNotContainsString('fullUrl', $code);
    }

    // ─── R13-6: "надіслано" for mail nobody had sent yet ─

    public function test_a_failed_approval_email_reaches_the_audit_log(): void
    {
        $order = Order::factory()->create(['authorized_person' => 'Алчук В.Г.']);

        (new OrderCreatedNotification($order))->failed(new \RuntimeException('SMTP refused'));

        $entry = AuditLog::where('event_type', 'approval_email_failed')->first();

        $this->assertNotNull($entry, 'A signatory who never got the email left no trace.');
        $this->assertStringContainsString($order->order_number, $entry->description);
        $this->assertSame($order->id, $entry->meta['order_id']);
    }

    public function test_the_operator_is_told_the_request_was_queued_not_delivered(): void
    {
        // The notification is ShouldQueue, so the controller cannot know whether
        // the mail arrived; it must not claim it did.
        $source = (string) file_get_contents(app_path('Http/Controllers/OrderController.php'));

        $this->assertStringNotContainsString('погодження надіслано на', $source);
        $this->assertStringContainsString('поставлено в чергу', $source);
    }

    // ─── R13-7: hard validation with a door in it ────────

    public function test_a_deactivated_machine_still_has_its_counter_validated(): void
    {
        // exists:equipment,id queries the raw table and ignores the soft-delete
        // scope, while find() applies it — so deactivating a machine while an
        // operator held the form let the reading skip the check entirely.
        $equipment = Equipment::factory()->create([
            'has_counter'     => true,
            'initial_counter' => 1_000,
        ]);
        $equipment->delete();

        $service = app(ShiftOpenService::class);
        $method = new \ReflectionMethod($service, 'validateMorningReading');

        $this->expectException(\RuntimeException::class);

        // Below the machine's own starting mileage: the check must still fire.
        $method->invoke($service, $equipment->id, 500);
    }

    public function test_a_reading_above_the_last_known_value_still_passes(): void
    {
        $equipment = Equipment::factory()->create([
            'has_counter'     => true,
            'initial_counter' => 1_000,
        ]);

        $service = app(ShiftOpenService::class);
        $method = new \ReflectionMethod($service, 'validateMorningReading');

        $method->invoke($service, $equipment->id, 1_500);

        $this->assertTrue(true, 'A legitimate reading must not be rejected.');
    }

    public function test_an_unknown_machine_is_refused_rather_than_skipped(): void
    {
        $service = app(ShiftOpenService::class);
        $method = new \ReflectionMethod($service, 'validateMorningReading');

        $this->expectException(\RuntimeException::class);

        $method->invoke($service, 999_999, 10);
    }

    /**
     * The crash reporter must send through TelegramService, not around it
     * (finding R4-2, fourth audit pass 2026-08-09). The hand-rolled copy in
     * bootstrap/app.php rotted one PR behind twice in one day: #92 mirrored
     * the response check into it, #94 taught deliver() the plain-text retry
     * — and the mirror stayed still, so a backtick inside an exception
     * message (SQL, HTML — the likeliest text there is) lost the one alert
     * whose silence is most expensive. A source assertion, because the
     * reporter early-returns in the testing environment by design and no
     * HTTP test can reach it.
     */
    public function test_the_error_reporter_sends_through_the_service_not_around_it(): void
    {
        $this->assertStringNotContainsString(
            'api.telegram.org',
            (string) file_get_contents(base_path('bootstrap/app.php')),
            'The reporter must delegate to TelegramService::sendCritical() — a hand-rolled copy rots.',
        );
    }
}
