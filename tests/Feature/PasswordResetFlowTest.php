<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The password reset flow had no tests at all — AuthController sat at 0%.
 *
 * Its two success messages were written to a session key nothing reads
 * (`flash`, an array, where the Inertia middleware shares `success`), so a
 * user who asked for a reset link got no answer whatsoever: no confirmation,
 * no error, the form just sat there.
 */
class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_requesting_a_link_tells_the_user_it_was_sent(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'operator@example.com']);

        $response = $this->post(route('password.email'), ['email' => $user->email]);

        $response->assertSessionHas('success');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_an_unknown_address_reports_an_error_rather_than_nothing(): void
    {
        Notification::fake();

        $response = $this->post(route('password.email'), ['email' => 'nobody@example.com']);

        $response->assertSessionHasErrors('email');
        Notification::assertNothingSent();
    }

    public function test_a_completed_reset_lets_the_user_in_with_the_new_password(): void
    {
        Notification::fake();

        $user  = User::factory()->create(['email' => 'operator@example.com']);
        $token = app('auth.password.broker')->createToken($user);

        $response = $this->post(route('password.update'), [
            'email'                 => $user->email,
            'token'                 => $token,
            'password'              => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('success');

        $this->assertTrue(
            auth()->validate(['email' => $user->email, 'password' => 'new-password-123']),
            'The new password must work.',
        );
    }

    public function test_a_bad_token_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'operator@example.com']);

        $response = $this->post(route('password.update'), [
            'email'                 => $user->email,
            'token'                 => 'not-a-real-token',
            'password'              => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertSessionHasErrors('email');

        $this->assertFalse(
            auth()->validate(['email' => $user->email, 'password' => 'new-password-123']),
            'A rejected reset must not change the password.',
        );
    }

    /**
     * Both endpoints are unauthenticated: one sends mail, the other guesses
     * tokens. Login was throttled to 5/min and these two were not.
     */
    public function test_the_reset_endpoints_are_throttled(): void
    {
        Notification::fake();

        $lastStatus = null;
        for ($i = 0; $i < 7; $i++) {
            $lastStatus = $this->post(route('password.email'), [
                'email' => "probe{$i}@example.com",
            ])->getStatusCode();
        }

        $this->assertSame(
            429,
            $lastStatus,
            'Walking a list of addresses must run into the rate limit.',
        );
    }
}
