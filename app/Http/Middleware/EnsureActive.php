<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureActive Middleware
 *
 * Checks that the authenticated user is still active on every request.
 * If the user was deactivated while logged in, they are immediately
 * logged out and redirected to the login page.
 *
 * Complements LoginRequest::authenticate() which only checks at login time.
 */
class EnsureActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['login' => 'Ваш обліковий запис деактивовано. Зверніться до адміністратора.']);
        }

        return $next($request);
    }
}
