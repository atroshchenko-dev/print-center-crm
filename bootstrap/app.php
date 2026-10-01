<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Services\TelegramService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // `/ping` is the endpoint UptimeRobot calls every few minutes, and the
        // monitor only needs "it only runs SELECT 1". In
        // routes/web.php it did more: the web group starts a session on every
        // request, so a monitor with no cookie jar minted a fresh session
        // record each time — a few hundred rows a day on the `database` driver,
        // written by the one request whose whole job is to observe.
        //
        // Registered here rather than excluded there. Subtracting from the
        // group was tried first and does not hold: drop StartSession and the
        // CSRF middleware still asks the session for a token on the way out,
        // and the list of things to subtract is only knowable by running into
        // each one. Outside the group there is nothing to subtract.
        //
        // SecurityHeaders is kept deliberately — it needs no session, and a
        // public endpoint should carry the same headers as the rest.
        then: function (): void {
            Route::get('ping', [HealthController::class, 'ping'])
                ->middleware(['throttle:60,1', SecurityHeaders::class]);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SecurityHeaders::class,
            EnsureActive::class,
            HandleInertiaRequests::class,
        ]);

        // Middleware aliases
        $middleware->alias([
            'role'       => EnsureRole::class,
            'permission' => EnsurePermission::class,
        ]);

        // CSRF exclusion only for external webhook (GitHub has no XSRF-TOKEN cookie).
        // Inertia.js routes are protected automatically via X-XSRF-TOKEN header.
        $middleware->validateCsrfTokens(except: [
            'deploy/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // ─── Telegram Error Alerts ───────────────────────
        // DESIGN DECISION: Error alerts bypass TelegramService::send() intentionally.
        // The 'telegram_enabled' setting controls OPERATIONAL notifications (shifts, orders, etc.)
        // but system error monitoring must ALWAYS work regardless of operator preferences.
        $exceptions->reportable(function (Throwable $e) {
            // Only in production, only if configured
            if (app()->environment('testing') || ! config('services.telegram.bot_token')) {
                return;
            }

            // Rate limit: 1 notification per unique error per 5 minutes.
            // The gate must not be able to kill the alert: with
            // CACHE_STORE=database a DB outage made Cache::has() itself throw
            // from inside this callback — masking the original error and
            // silencing Telegram exactly when it mattered (fifth audit pass).
            // rescue(report: false) — reporting from inside the reporter loops.
            $key = 'tg_alert:'.md5($e->getFile().$e->getLine().$e->getMessage());
            if (rescue(fn () => Cache::has($key), false, false)) {
                return;
            }
            rescue(fn () => Cache::put($key, true, 300), null, false);

            // Path only — fullUrl() would leak one-time approval tokens and
            // their HMAC signature into the operators' Telegram chat.
            $url = request() ? '/'.ltrim(request()->path(), '/') : 'CLI';
            $user = auth()->user()?->name ?? 'Guest';
            $method = request()?->method() ?? '';
            $file = basename($e->getFile()).':'.$e->getLine();
            $message = mb_substr($e->getMessage(), 0, 200);

            $text = "🔴 *CRM Print Error*\n\n"
                  ."📍 `{$method} {$url}`\n"
                  ."👤 {$user}\n"
                  ."❌ `{$message}`\n"
                  ."📄 `{$file}`\n"
                  .'🕐 '.now()->timezone('Europe/Kyiv')->format('H:i:s d.m.Y');

            try {
                // Through the service, not around it: the hand-rolled copy
                // that lived here rotted one PR behind twice in a day — #92
                // mirrored the response check in, #94 taught deliver() the
                // plain-text retry and the mirror stayed still. A
                // crash report is sendCritical() by clause 1 of its own rule:
                // the system reporting on itself, past the operational toggle.
                app(TelegramService::class)->sendCritical($text);
            } catch (Throwable $telegramError) {
                Log::warning('Telegram alert failed: '.$telegramError->getMessage());
            }
        });

    })->create();
