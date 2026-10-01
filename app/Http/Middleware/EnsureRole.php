<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureRole Middleware
 *
 * Usage in routes: ->middleware('role:admin') or ->middleware('role:admin,executor')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $allowedRoles = array_map(
            fn (string $r) => UserRole::from($r),
            $roles,
        );

        if (! in_array($user->role, $allowedRoles, true)) {
            abort(403, 'Доступ заборонено.');
        }

        return $next($request);
    }
}
