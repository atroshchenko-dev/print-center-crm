<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ActivatePendingPricesJob;
use App\Jobs\AutoCloseShiftJob;
use App\Jobs\ResetDepartmentLimitsJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The queue against the schema it is configured to use.
 *
 * `.env` carries QUEUE_CONNECTION=database and config/queue.php defaults to the
 * same, but no migration ever created the `jobs` table. Every dispatch raised
 * "relation jobs does not exist", so the three scheduled business jobs — price
 * activation, shift auto-close, monthly limit reset — never reached the queue.
 *
 * Nothing caught it for eleven rounds because phpunit.xml sets
 * QUEUE_CONNECTION=sync: under `sync` a job runs inline and no table is touched,
 * so the whole suite passed against a schema production could not use. These
 * tests deliberately switch the connection back to `database`, which is the only
 * way to test the arrangement that actually ships.
 */
class QueueInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(storage_path('app/scheduler-heartbeat'));

        parent::tearDown();
    }

    public function test_the_table_the_configured_queue_writes_to_exists(): void
    {
        $this->assertTrue(
            Schema::hasTable('jobs'),
            'QUEUE_CONNECTION=database has nowhere to write.',
        );
    }

    public function test_a_scheduled_job_can_actually_be_queued(): void
    {
        config(['queue.default' => 'database']);

        dispatch(new ActivatePendingPricesJob);

        $this->assertSame(
            1,
            DB::table('jobs')->count(),
            'The job was accepted but never landed in the queue.',
        );
    }

    public function test_every_scheduled_job_class_survives_being_queued(): void
    {
        config(['queue.default' => 'database']);

        // Queueing exercises serialisation, which is the other way a scheduled
        // job dies silently.
        //
        // Two of the three are dispatched by routes/console.php. The third —
        // ResetDepartmentLimitsJob — is deliberately not scheduled any more
        // (CLOSEOUT §1.12, audit R33-2) and is kept here anyway: if the quota
        // is ever turned on, this is the check that says the job still
        // survives the queue.
        foreach ([
            ActivatePendingPricesJob::class,
            AutoCloseShiftJob::class,
            ResetDepartmentLimitsJob::class,
        ] as $class) {
            dispatch(new $class);
        }

        $this->assertSame(3, DB::table('jobs')->count());
    }

    public function test_health_calls_a_missing_jobs_table_a_failure(): void
    {
        // The state production was in: configured for the database queue, with
        // no table behind it. Silence is exactly what made this last so long,
        // so the endpoint has to be loud about it.
        config(['queue.default' => 'database']);
        file_put_contents(storage_path('app/scheduler-heartbeat'), (string) now()->timestamp);
        Schema::drop('jobs');

        $response = $this->actingAs(User::factory()->admin()->create())->get('/health');

        $this->assertStringContainsString('jobs table does not exist', $response->json('checks.queue'));
        $this->assertSame('unhealthy', $response->json('status'));
    }

    public function test_ping_survives_a_missing_jobs_table(): void
    {
        // A public endpoint must answer ok/down, never 500 — otherwise the
        // monitor learns even less than it did from SELECT 1.
        config(['queue.default' => 'database']);
        file_put_contents(storage_path('app/scheduler-heartbeat'), (string) now()->timestamp);
        Schema::drop('jobs');

        $this->get('/ping')->assertOk();
    }
}
