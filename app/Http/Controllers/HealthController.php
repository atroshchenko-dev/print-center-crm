<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HealthController — System health check endpoint.
 *
 * Returns JSON status of core dependencies:
 * - Database connectivity
 * - Cache driver
 * - Disk space
 * - Queue worker and backlog
 * - Scheduler heartbeat
 *
 * Two endpoints, and the split matters:
 *
 *   /health — admin only, the full picture. Anything needing human action makes
 *             it unhealthy, including failed jobs, which stay failed until
 *             someone clears them.
 *   /ping   — public, watched by the external monitor. Reports only conditions
 *             that mean "failing right now" and that clear themselves once
 *             fixed: database, scheduler, queue backlog, critical disk. Failed
 *             jobs are deliberately NOT here — a job that failed last week would
 *             pin the monitor red until cleared, and a monitor that is always
 *             red is a monitor nobody reads. That is the R8-4 lesson.
 *
 * Before this, both endpoints were blind to everything that stops quietly:
 * /health wrote "warning: low disk space" and "warning: N failed jobs" without
 * touching its own verdict, and /ping ran SELECT 1 and nothing else. A dead
 * queue worker, a dead cron and a full disk all produced a green 200.
 */
class HealthController extends Controller
{
    /** Cron runs every minute; this much silence means it is not running. */
    private const SCHEDULER_STALE_SECONDS = 300;

    /** A job nobody has picked up for this long means no worker is alive. */
    private const QUEUE_STALL_SECONDS = 900;

    /** Below this the disk is not "low", it is about to stop the application. */
    private const DISK_CRITICAL_GB = 1.0;

    public function __invoke(): JsonResponse
    {
        $checks = [];
        $healthy = true;

        // Database
        try {
            DB::select('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'fail: ' . $e->getMessage();
            $healthy = false;
        }

        // Cache
        try {
            Cache::put('health_check', true, 10);
            $checks['cache'] = Cache::get('health_check') ? 'ok' : 'fail';
            Cache::forget('health_check');
        } catch (\Throwable $e) {
            $checks['cache'] = 'fail: ' . $e->getMessage();
            $healthy = false;
        }

        // Disk space — a warning that changed nothing is how this went unseen.
        $freeGB = $this->freeDiskGb();
        $checks['disk_free_gb'] = $freeGB;
        if ($freeGB !== null && $freeGB < self::DISK_CRITICAL_GB) {
            $checks['disk'] = 'fail: low disk space';
            $healthy = false;
        } else {
            $checks['disk'] = 'ok';
        }

        // Redis. Left as-is deliberately: the shipped .env uses database drivers
        // for cache, session and queue, so whether Redis is even expected to run
        // is a question for the server, not for this file.
        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $redis->ping();
            $checks['redis'] = 'ok';
        } catch (\Throwable $e) {
            $checks['redis'] = 'fail: ' . $e->getMessage();
            $healthy = false;
        }

        // Queue: backlog age answers "is a worker alive", the failed count
        // answers "did something break and stay broken". Both count here.
        if ($this->queueTableMissing()) {
            // Not a warning. Nothing can be queued at all in this state.
            $checks['queue'] = 'fail: queue is set to "database" but the jobs table does not exist';
            $healthy = false;
        } else {
            try {
                $failed     = DB::table('failed_jobs')->count();
                $stalledFor = $this->queueStalledForSeconds();

                $checks['queue_pending'] = DB::table('jobs')->count();
                $checks['queue_failed']  = $failed;
                $checks['queue']         = 'ok';

                if ($stalledFor !== null && $stalledFor > self::QUEUE_STALL_SECONDS) {
                    $checks['queue'] = "fail: oldest job waiting {$stalledFor}s — worker not consuming";
                    $healthy = false;
                } elseif ($failed > 0) {
                    $checks['queue'] = "fail: {$failed} failed jobs";
                    $healthy = false;
                }
            } catch (\Throwable $e) {
                $checks['queue'] = 'fail: ' . $e->getMessage();
                $healthy = false;
            }
        }

        // Scheduler heartbeat — see routes/console.php.
        $staleFor = $this->schedulerStaleForSeconds();
        if ($staleFor === null) {
            $checks['scheduler'] = 'fail: no heartbeat recorded';
            $healthy = false;
        } elseif ($staleFor > self::SCHEDULER_STALE_SECONDS) {
            $checks['scheduler'] = "fail: last run {$staleFor}s ago";
            $healthy = false;
        } else {
            $checks['scheduler'] = 'ok';
        }

        // App info
        $checks['app_env'] = app()->environment();
        $checks['php_version'] = PHP_VERSION;
        $checks['laravel_version'] = app()->version();
        $checks['uptime'] = $this->getUptime();

        return response()->json([
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * Lightweight public ping for UptimeRobot.
     *
     * Still says nothing but ok/down — no versions, no counts, no paths. What
     * changed is what it looks at: a reachable database was never evidence that
     * the system was working, because the queue worker and cron can be dead
     * while PostgreSQL answers perfectly.
     */
    public function ping(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            return response()->json(['status' => 'down'], 503);
        }

        // Only a stamp that has gone stale counts here. A missing one means cron
        // was never proven alive on this machine — a fresh deploy looks exactly
        // the same — and reporting that to an external monitor would cry wolf on
        // every new install. /health says it plainly instead.
        $staleFor = $this->schedulerStaleForSeconds();
        if ($staleFor !== null && $staleFor > self::SCHEDULER_STALE_SECONDS) {
            return response()->json(['status' => 'down'], 503);
        }

        $stalledFor = $this->queueStalledForSeconds();
        if ($stalledFor !== null && $stalledFor > self::QUEUE_STALL_SECONDS) {
            return response()->json(['status' => 'down'], 503);
        }

        $freeGB = $this->freeDiskGb();
        if ($freeGB !== null && $freeGB < self::DISK_CRITICAL_GB) {
            return response()->json(['status' => 'down'], 503);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Seconds since the scheduler last checked in, or null if it never has.
     *
     * Null and stale are different facts and are treated differently:
     * null means cron has never been proven alive here — true of a machine an
     * hour into its first deploy — while a stale stamp means it ran and then
     * stopped, which is the failure this was built for. The file survives
     * `optimize:clear`, so once cron has run even once, staleness is the only
     * state that matters.
     */
    private function schedulerStaleForSeconds(): ?int
    {
        $path = storage_path('app/scheduler-heartbeat');

        if (! is_file($path)) {
            return null;
        }

        $stamp = @file_get_contents($path);

        if (! is_string($stamp) || ! is_numeric(trim($stamp))) {
            return null;
        }

        return max(0, now()->timestamp - (int) trim($stamp));
    }

    /**
     * How long the oldest queued job has been waiting, or null if none is —
     * or if the table it would live in does not exist (see queueTableMissing()).
     * /ping calls this, so it must never throw: a public endpoint answering 500
     * tells a monitor even less than the SELECT 1 it replaced.
     */
    private function queueStalledForSeconds(): ?int
    {
        try {
            $oldest = DB::table('jobs')->min('available_at');
        } catch (\Throwable) {
            return null;
        }

        if ($oldest === null) {
            return null;
        }

        return max(0, now()->timestamp - (int) $oldest);
    }

    /**
     * The queue is configured against a table this codebase never creates.
     *
     * `QUEUE_CONNECTION=database` with no `jobs` table means every dispatch
     * raises "relation jobs does not exist" — the three scheduled business jobs
     * cannot even be queued, let alone run. Worth its own check
     * precisely because the symptom is silence.
     */
    private function queueTableMissing(): bool
    {
        if (config('queue.default') !== 'database') {
            return false;
        }

        try {
            return ! Schema::hasTable('jobs');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Free space on the application disk in GB, or null if it cannot be read.
     */
    private function freeDiskGb(): ?float
    {
        $freeBytes = @disk_free_space(base_path());

        if ($freeBytes === false) {
            return null;
        }

        return round($freeBytes / 1073741824, 2);
    }

    /**
     * Get server uptime (Linux only, graceful fallback).
     */
    private function getUptime(): string
    {
        try {
            if (PHP_OS_FAMILY === 'Linux' && file_exists('/proc/uptime')) {
                $seconds = (int) explode(' ', file_get_contents('/proc/uptime'))[0];
                $days = intdiv($seconds, 86400);
                $hours = intdiv($seconds % 86400, 3600);
                return "{$days}d {$hours}h";
            }
        } catch (\Throwable) {}

        return 'N/A';
    }
}
