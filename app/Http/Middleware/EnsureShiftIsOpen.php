<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Shift;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureShiftIsOpen Middleware
 *
 * Blocks order creation and cash operations unless a shift is open.
 * The shift's own date is irrelevant — one opened yesterday evening is still
 * current until the 03:00 auto-close (TZ §4.4).
 */
class EnsureShiftIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $shift = Shift::current()->first();

        if (! $shift) {
            return redirect()->route('shifts.open.form')
                ->with('error', 'Будь ласка, відкрийте нову зміну перед початком роботи.');
        }

        $request->attributes->set('current_shift', $shift);

        return $next($request);
    }
}
