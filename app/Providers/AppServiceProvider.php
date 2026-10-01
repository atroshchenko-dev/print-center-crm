<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // ─── Numeric route bindings answer typos with 404 ─
        // Every one of these binds a bigint id; without a pattern,
        // `/orders/abc` reached the database and died in the cast — a 500
        // and a Telegram alert for a mistyped URL, on every numeric {param}
        // in routes/web.php at once.
        // `token` and `method` stay out: those two really are strings.
        Route::patterns(array_fill_keys([
            'order', 'service', 'group', 'user', 'signatory', 'option',
            'material', 'equipment', 'department', 'category', 'tier',
            'item', 'id', 'initiator',
        ], '[0-9]+'));

        // ─── Slow Query Logging ──────────────────────────
        // Log queries exceeding 500ms to help identify performance issues early.
        // Only active in production/staging (not during tests or local dev).
        if (! $this->app->environment('testing', 'local')) {
            DB::listen(function ($query): void {
                if ($query->time > 500) {
                    Log::warning('[SlowQuery]', [
                        'sql'      => $query->sql,
                        'bindings' => $this->safeBindings($query->sql, $query->bindings),
                        'time_ms'  => $query->time,
                        // Path, not fullUrl(), for the reason already written out
                        // in bootstrap/app.php: a full URL carries one-time
                        // approval tokens and their HMAC signature. That rule was
                        // applied to the Telegram channel and missed here, so the
                        // same secret was landing in laravel.log.
                        'url' => $this->app->runningInConsole()
                            ? 'CLI'
                            : '/'.ltrim(request()->path(), '/'),
                        'user' => auth()->user()?->name ?? 'system',
                    ]);
                }
            });
        }
    }

    /**
     * Bindings are the other half of the same leak: a slow
     * `select … from order_approvals where token = ?` writes the token itself
     * into the log, and fixing only the URL would have left that untouched.
     *
     * @param  array<int|string, mixed>  $bindings
     * @return array<int|string, mixed>|string
     */
    private function safeBindings(string $sql, array $bindings): array|string
    {
        $sensitive = ['order_approvals', 'token', 'password', 'secret'];

        foreach ($sensitive as $needle) {
            if (stripos($sql, $needle) !== false) {
                return '[redacted: '.count($bindings).' binding(s)]';
            }
        }

        return $bindings;
    }
}
