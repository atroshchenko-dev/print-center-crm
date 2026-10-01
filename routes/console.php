<?php

declare(strict_types=1);

use App\Jobs\ActivatePendingPricesJob;
use App\Jobs\AutoCloseShiftJob;
use Illuminate\Support\Facades\Schedule;

/**
 * CRM Print — Scheduled Tasks
 *
 * All times are in Europe/Kyiv (DST-aware).
 * Times stored in DB are UTC; Laravel's ->timezone() handles DST conversion.
 *
 * Schedule (every task guarded by withoutOverlapping):
 *   00:01 daily    — Activate pending material prices (TZ §3.2)
 *   03:00 daily    — Auto-close any open shifts (TZ §4.4)
 *   03:30 daily    — Prune personal data past retention (TZ §9)
 *   04:00 daily    — Database backup (TZ §6)
 *   05:00 daily    — Backup health check
 *   08:00 daily    — Inventory depletion forecast
 *   18:00 daily    — Telegram daily summary
 *   09:00 Mondays  — Telegram weekly digest
 *   every minute   — Scheduler heartbeat (the dead-man's-switch below)
 *
 * `ResetDepartmentLimitsJob` is **not** here, and that is a recorded decision
 * rather than an omission — see the note where it used to stand.
 */
// Dead-man's-switch for the scheduler itself.
//
// Everything else in this file is guarded BY the scheduler — including
// backup:check-health, the one alarm about the backup. So when cron stops, the
// backup and the alarm about its absence stop together, and nothing says so:
// /ping kept answering 200 because PHP and the database were still up.
//
// This writes a timestamp every minute and is the only task here that exists
// for something outside to read. HealthController treats a stale or missing
// stamp as a failure, which is what finally makes a dead cron visible.
// Written to a file, not the cache, on purpose: deploy.sh runs
// `php artisan optimize:clear`, so a cached stamp would be wiped by every
// release and the monitor would go red on each deploy — a false alarm is how a
// monitor stops being read.
//
// The guards below are the house rule for every task in this file (audit R6),
// and this one keeps them rather than claiming an exemption: the lock expires
// after a minute, so a stuck mutex costs one heartbeat and not the alarm.
Schedule::call(function (): void {
    $path = storage_path('app/scheduler-heartbeat');

    @mkdir(dirname($path), 0775, true);
    @file_put_contents($path, (string) now()->timestamp);
})
    ->everyMinute()
    ->timezone('Europe/Kyiv')
    ->name('scheduler-heartbeat')
    ->withoutOverlapping(1);

Schedule::job(new ActivatePendingPricesJob)
    ->dailyAt('00:01')
    ->timezone('Europe/Kyiv')
    ->name('activate-pending-prices')
    ->withoutOverlapping();

Schedule::job(new AutoCloseShiftJob)
    ->dailyAt('03:00')
    ->timezone('Europe/Kyiv')
    ->name('auto-close-shift')
    ->withoutOverlapping();

// DST catch-up: on the last Sunday of March Kyiv
// jumps 03:00 → 04:00, and a tz-scheduled dailyAt() inside the skipped hour
// never matches that day — Saturday's shift stood open all Sunday. The job
// is idempotent, so this second run is a no-op on the other 364 days. The
// autumn doubling (03:xx twice) needs nothing: two idempotent runs.
Schedule::job(new AutoCloseShiftJob)
    ->dailyAt('04:10')
    ->timezone('Europe/Kyiv')
    ->name('auto-close-shift-dst-catchup')
    ->withoutOverlapping();

// Database backup (TZ §6)
Schedule::command('backup:run --only-db')
    ->dailyAt('04:00')
    ->timezone('Europe/Kyiv')
    ->name('database-backup')
    ->withoutOverlapping();

// Monthly department limit reset (TZ §3.1) — **deliberately not scheduled.**
//
// Owner's decision, 2026-08-04 (CLOSEOUT §1.12): the cost-centre quota is not
// used, and the signatory-group pool is the one quota this system has.
//
// It reset nothing every month since the day it was written, and that was not
// visible from here: nothing in the product can create a `department_limits`
// row — no form, no controller, no seeder, no migration — so the job's
// `where('current_usage', '>', 0)` could never match. Measured on production
// 2026-08-04: `department_limits: 0`.
//
// The class stays. Turning the quota back on means giving it a switch **and**
// putting this line back, and `ScheduleIntegrityTest` fails the moment the
// second half happens without the first.
// Daily Telegram summary (after shift typically closes)
Schedule::command('telegram:daily-summary')
    ->dailyAt('18:00')
    ->timezone('Europe/Kyiv')
    ->name('telegram-daily-summary')
    ->withoutOverlapping();

// Backup health check (after backup runs at 04:00)
Schedule::command('backup:check-health')
    ->dailyAt('05:00')
    ->timezone('Europe/Kyiv')
    ->name('backup-health-check')
    ->withoutOverlapping();

// Inventory depletion forecast (proactive alert before stock runs out)
Schedule::command('inventory:forecast')
    ->dailyAt('08:00')
    ->timezone('Europe/Kyiv')
    ->name('inventory-forecast')
    ->withoutOverlapping();

// Personal data retention (TZ §9 / audit M-7) — runs before the backup, so a
// nightly dump never captures data that was already due for removal.
Schedule::command('privacy:prune')
    ->dailyAt('03:30')
    ->timezone('Europe/Kyiv')
    ->name('privacy-prune')
    ->withoutOverlapping();

// Same DST catch-up as auto-close: 03:30 also does not exist on the
// spring-forward Sunday. Once a year this run lands after the 04:00 backup
// instead of before it — that night's dump keeps one extra day of data due
// for removal, and the next night's is clean again.
Schedule::command('privacy:prune')
    ->dailyAt('04:40')
    ->timezone('Europe/Kyiv')
    ->name('privacy-prune-dst-catchup')
    ->withoutOverlapping();

// Weekly business digest (every Monday at 09:00)
Schedule::command('telegram:weekly-digest')
    ->weeklyOn(1, '09:00')
    ->timezone('Europe/Kyiv')
    ->name('telegram-weekly-digest')
    ->withoutOverlapping();
