<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the monitors actually see.
 *
 * Three things in this system stop without saying anything: the queue worker,
 * cron, and the disk filling up. None of them touched either endpoint —
 * /health wrote "warning: …" without changing its own verdict, and /ping ran
 * SELECT 1, which a perfectly healthy PostgreSQL answers while nothing else in
 * the system is running.
 *
 * These tests pin the two endpoints to their separate jobs: /ping reports what
 * is failing right now and recovers on its own, /health additionally reports
 * what a human has to go and clear.
 */
class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The healthy baseline: cron checked in a moment ago.
        $this->heartbeat(now());
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/scheduler-heartbeat'));

        parent::tearDown();
    }

    // ─── /ping: the endpoint the outside world watches ───

    public function test_ping_is_ok_when_the_scheduler_and_queue_are_alive(): void
    {
        $this->get('/ping')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    /**
     * The endpoint an external monitor calls every few minutes must not write.
     *
     * Living in `routes/web.php` it got the web group, so every cookie-less
     * request from UptimeRobot started a session and minted a record — a few
     * hundred rows a day on the `database` driver, from the one request whose
     * whole job is to observe. The audit had called this endpoint "only
     * SELECT 1" three times over (audit round 22, §3.2).
     */
    public function test_ping_starts_no_session_for_the_monitor(): void
    {
        $response = $this->get('/ping')->assertOk();

        $this->assertEmpty(
            $response->headers->getCookies(),
            '/ping handed the monitor a cookie, which means a session was started for it.',
        );

        $this->assertFalse(
            app('session')->isStarted(),
            '/ping started a session. An endpoint that only reports must not also write one.',
        );
    }

    public function test_ping_reports_down_when_cron_has_stopped(): void
    {
        // The case that mattered: cron dies, so the backup stops AND the alarm
        // about the backup stops, while the database keeps answering.
        $this->heartbeat(now()->subMinutes(30));

        $this->get('/ping')
            ->assertStatus(503)
            ->assertJson(['status' => 'down']);
    }

    public function test_ping_tolerates_a_scheduler_that_has_never_checked_in(): void
    {
        // A machine an hour into its first deploy looks exactly like this, and
        // crying wolf at every new install is how a monitor stops being read.
        // /health states it plainly instead — see below.
        @unlink(storage_path('app/scheduler-heartbeat'));

        $this->get('/ping')->assertOk();
    }

    public function test_ping_reports_down_when_no_worker_is_consuming_the_queue(): void
    {
        $this->queueJobAvailableAt(now()->subHour());

        $this->get('/ping')
            ->assertStatus(503)
            ->assertJson(['status' => 'down']);
    }

    public function test_ping_stays_ok_while_a_job_is_merely_recent(): void
    {
        // A job queued seconds ago is normal traffic, not a stalled worker.
        $this->queueJobAvailableAt(now()->subSeconds(5));

        $this->get('/ping')->assertOk();
    }

    public function test_ping_ignores_jobs_that_failed_and_were_never_cleared(): void
    {
        // Deliberate: a job that failed last week must not pin the external
        // monitor red forever. That belongs to /health, where a human looks.
        $this->failedJob();

        $this->get('/ping')->assertOk();
    }

    // ─── /health: the full picture, admin only ───────────

    public function test_health_counts_failed_jobs_against_its_own_verdict(): void
    {
        $this->failedJob();

        $response = $this->actingAs(User::factory()->admin()->create())->get('/health');

        $this->assertStringContainsString('fail', $response->json('checks.queue'));
        $this->assertSame(1, $response->json('checks.queue_failed'));
    }

    public function test_health_reports_a_stopped_scheduler(): void
    {
        $this->heartbeat(now()->subMinutes(30));

        $response = $this->actingAs(User::factory()->admin()->create())->get('/health');

        $this->assertStringContainsString('fail', $response->json('checks.scheduler'));
    }

    public function test_health_says_plainly_that_cron_was_never_proven_alive(): void
    {
        // The half /ping deliberately stays quiet about, so it has to be stated
        // somewhere — otherwise "cron was never set up" is invisible everywhere.
        @unlink(storage_path('app/scheduler-heartbeat'));

        $response = $this->actingAs(User::factory()->admin()->create())->get('/health');

        $this->assertStringContainsString('fail', $response->json('checks.scheduler'));
    }

    public function test_health_is_content_with_a_scheduler_that_just_ran(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())->get('/health');

        $this->assertSame('ok', $response->json('checks.scheduler'));
    }

    public function test_health_stays_admin_only(): void
    {
        // It answers with versions, free space and queue depth; that was already
        // the rule and must survive this change.
        $this->actingAs(User::factory()->executor()->create())
            ->get('/health')
            ->assertForbidden();
    }

    // ─── Helpers ─────────────────────────────────────────

    private function heartbeat(\DateTimeInterface $at): void
    {
        $path = storage_path('app/scheduler-heartbeat');

        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, (string) $at->getTimestamp());
    }

    private function queueJobAvailableAt(\DateTimeInterface $at): void
    {
        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => '{}',
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => $at->getTimestamp(),
            'created_at'   => $at->getTimestamp(),
        ]);
    }

    private function failedJob(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid'       => (string) Str::uuid(),
            'connection' => 'database',
            'queue'      => 'default',
            'payload'    => '{}',
            'exception'  => 'test',
            'failed_at'  => now(),
        ]);
    }
}
