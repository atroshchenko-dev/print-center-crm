<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The front door, at 0% through fourteen rounds.
 *
 * Every test in this suite reaches the application through actingAs(), so
 * nothing had ever posted the login form: not the five-attempt lockout, not the
 * refusal of a deactivated account (ТЗ §2.1), not the choice between logging in
 * by email and by name. All three are decisions this request makes alone.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('оператор|127.0.0.1');
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create([
            'name'      => 'Оператор',
            'email'     => 'operator@example.edu',
            'password'  => Hash::make('secret-pass'),
            'is_active' => true,
            ...$attributes,
        ]);
    }

    public function test_an_operator_can_sign_in_with_an_email(): void
    {
        $user = $this->user();

        $this->post(route('login.submit'), [
            'login'    => 'operator@example.edu',
            'password' => 'secret-pass',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    /**
     * The field is chosen by whether the value contains "@" — a name with no
     * at-sign is looked up as `name`, which is the only way an account without
     * an email address can sign in at all.
     */
    public function test_an_operator_can_sign_in_with_a_name(): void
    {
        $user = $this->user();

        $this->post(route('login.submit'), [
            'login'    => 'Оператор',
            'password' => 'secret-pass',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->user();

        $this->post(route('login.submit'), [
            'login'    => 'operator@example.edu',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    /**
     * ТЗ §2.1: a deactivated account keeps its password and must still not get
     * in. The credentials pass, so this refusal happens after Auth::attempt has
     * already succeeded — and it has to log the session back out, or the guard
     * would be left holding a user the check just rejected.
     */
    public function test_a_deactivated_account_is_refused_after_its_password_checks_out(): void
    {
        $this->user(['is_active' => false]);

        $this->post(route('login.submit'), [
            'login'    => 'operator@example.edu',
            'password' => 'secret-pass',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    /**
     * There are two limiters in front of this form, and the route's is the one
     * a person meets first: `throttle:5,1` counts *requests* per IP, while
     * LoginRequest counts *failures* per login+IP. Six posts in a minute never
     * reach the second one.
     */
    public function test_the_route_stops_a_sixth_attempt_within_the_minute(): void
    {
        $this->user();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('login.submit'), [
                'login'    => 'operator@example.edu',
                'password' => 'not-the-password',
            ]);
        }

        $this->post(route('login.submit'), [
            'login'    => 'operator@example.edu',
            'password' => 'secret-pass',
        ])->assertStatus(429);

        $this->assertGuest();
    }

    public function test_five_wrong_passwords_lock_the_account_out(): void
    {
        // The request's own limiter, with the route's counter out of the way —
        // it is what still holds when the per-IP window has rolled over.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->user();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('login.submit'), [
                'login'    => 'operator@example.edu',
                'password' => 'not-the-password',
            ]);
        }

        // The sixth is refused by the limiter, not by the password check — so
        // the right password does not get through either.
        $response = $this->post(route('login.submit'), [
            'login'    => 'operator@example.edu',
            'password' => 'secret-pass',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest();

        $errors = session('errors')->get('login');
        $this->assertStringNotContainsString('невірні', mb_strtolower($errors[0]));
    }

    /**
     * The limiter is keyed on login + IP, so one account being hammered must not
     * lock a different one out.
     */
    public function test_the_lockout_follows_the_login_it_was_earned_on(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->user();
        $other = $this->user(['name' => 'Бухгалтер', 'email' => 'accountant@example.edu']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('login.submit'), [
                'login'    => 'operator@example.edu',
                'password' => 'not-the-password',
            ]);
        }

        $this->post(route('login.submit'), [
            'login'    => 'accountant@example.edu',
            'password' => 'secret-pass',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($other);
    }

    /**
     * A successful sign-in clears the counter, or four bad days in a row would
     * add up to a lockout on the fifth.
     */
    public function test_signing_in_clears_the_attempts_behind_it(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->user();

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->post(route('login.submit'), [
                'login'    => 'operator@example.edu',
                'password' => 'not-the-password',
            ]);
        }

        $this->post(route('login.submit'), [
            'login'    => 'operator@example.edu',
            'password' => 'secret-pass',
        ])->assertRedirect(route('dashboard'));

        $this->post(route('logout'));

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->post(route('login.submit'), [
                'login'    => 'operator@example.edu',
                'password' => 'not-the-password',
            ]);
        }

        $this->post(route('login.submit'), [
            'login'    => 'operator@example.edu',
            'password' => 'secret-pass',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_the_form_requires_both_fields(): void
    {
        $this->post(route('login.submit'), [])
            ->assertSessionHasErrors(['login', 'password']);
    }
}
