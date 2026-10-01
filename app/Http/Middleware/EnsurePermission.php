<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsurePermission Middleware
 *
 * Checks that the user has the required module permission.
 * Admin users always pass (full access bypass).
 *
 * Usage in routes: ->middleware(EnsurePermission::class . ':reports')
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Admin always has full access
        if ($user->isAdmin()) {
            return $next($request);
        }

        if (! $user->hasPermission($module)) {
            abort(403, 'Доступ заборонено. Немає дозволу на модуль: ' . $module);
        }

        return $next($request);
    }
}
