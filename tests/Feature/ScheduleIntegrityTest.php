<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * ScheduleIntegrityTest
 *
 * The audit report claimed every scheduled task was guarded by
 * withoutOverlapping() and pinned to Europe/Kyiv. Two of the nine were not —
 * the claim had never been checked against the file. It is checked here now,
 * so adding an unguarded task fails the build instead of the next audit.
 */
class ScheduleIntegrityTest extends TestCase
{
    /**
     * @return Event[]
     */
    private function events(): array
    {
        $events = app(Schedule::class)->events();

        // Without this, an empty schedule would make every check below pass
        // while proving nothing.
        $this->assertNotEmpty($events, 'No scheduled tasks were registered at all.');

        return $events;
    }

    public function test_every_scheduled_task_is_guarded_against_overlapping(): void
    {
        $unguarded = [];

        foreach ($this->events() as $event) {
            if (! $event->withoutOverlapping) {
                $unguarded[] = $event->description ?? $event->command ?? $event->getSummaryForDisplay();
            }
        }

        $this->assertSame([], $unguarded, 'Scheduled tasks missing withoutOverlapping().');
    }

    public function test_every_scheduled_task_runs_on_kyiv_time(): void
    {
        $wrongZone = [];

        foreach ($this->events() as $event) {
            if ((string) $event->timezone !== 'Europe/Kyiv') {
                $wrongZone[] = ($event->description ?? $event->command ?? $event->getSummaryForDisplay())
                    .' => '.var_export($event->timezone, true);
            }
        }

        $this->assertSame([], $wrongZone, 'Scheduled tasks not pinned to Europe/Kyiv.');
    }

    /**
     * 03:00 and 03:30 do not exist once a year: on the last Sunday of March
     * Kyiv jumps 03:00 → 04:00, and a tz-scheduled dailyAt() in the skipped
     * hour simply never matches that day. The
     * auto-close (ТЗ §4.4) and privacy:prune silently sat out the day. Both
     * jobs are idempotent, so each carries a catch-up run at a time that
     * exists every day of the year; on the other 364 days it is a no-op.
     */
    public function test_the_three_oclock_tasks_have_a_dst_catch_up(): void
    {
        $names = array_map(fn ($event) => (string) $event->description, $this->events());

        $this->assertContains('auto-close-shift-dst-catchup', $names);
        $this->assertContains('privacy-prune-dst-catchup', $names);
    }

    public function test_every_scheduled_task_is_named(): void
    {
        foreach ($this->events() as $event) {
            $this->assertNotNull(
                $event->description,
                'Unnamed scheduled task: '.$event->getSummaryForDisplay(),
            );
        }
    }

    /**
     * `backup:clean` stays out of the schedule — and that is a decision.
     *
     * The retention policy in `config/backup.php` is real (7 days all, 16 daily,
     * 8 weekly, 4 monthly, 2 yearly) and it has never run once: the only command
     * that reads it is scheduled nowhere. The owner decided to leave it that way
     * — the policy would delete 80–90 of ~100 archives to reclaim ~10 MB
     *.
     *
     * Round 28 found the cost of that decision living only in the CLOSEOUT:
     * README said «7-day retention» and listed the periods flat, both
     * reading as a rotation that happens. Three documents were corrected — and a
     * corrected document is not a mechanism. This is the mechanism: schedule the
     * command and this test fails, which is the moment to go and re-read the
     * decision before ~100 archives start disappearing nightly.
     */
    public function test_backup_clean_is_still_not_scheduled(): void
    {
        $commands = array_map(
            static fn ($event) => (string) ($event->command ?? $event->getSummaryForDisplay()),
            $this->events(),
        );

        foreach ($commands as $command) {
            $this->assertStringNotContainsString(
                'backup:clean',
                $command,
                'backup:clean has been scheduled. That reverses a recorded owner decision '
                .'and makes README wrong '
                .'about retention in the opposite direction. Update it, then delete this test.',
            );
        }
    }

    /**
     * The monthly quota reset stays out of the schedule — also a decision.
     *
     * It stood here from the first round to round 33 and reset nothing every
     * month of it, because nothing in the product can create the row it looks
     * for: the department form saves `name`, `type` and `is_active`, and no
     * seeder or migration writes `department_limits` either. Production held
     * zero rows when it was finally counted, so the whole
     * cost-centre quota — the first of the three rules in
     * `LimitService::recordForOrder()` — had never once been able to answer.
     *
     * The owner's decision of 2026-08-04 is that it is not used: the
     * signatory-group pool is this system's one quota, and it is the one that
     * warned on production on 2026-08-01 («700 з 620»).
     *
     * Scheduling this again without giving the quota a switch puts the silence
     * back, so the test names both halves.
     */
    public function test_the_department_limit_reset_is_still_not_scheduled(): void
    {
        $summaries = array_map(
            static fn ($event) => ($event->description ?? '').' '.$event->getSummaryForDisplay(),
            $this->events(),
        );

        foreach ($summaries as $summary) {
            $this->assertStringNotContainsString(
                'ResetDepartmentLimitsJob',
                $summary,
                'The department-limit reset is scheduled again. That reverses a recorded '
                .'owner decision. If the cost-centre quota is being '
                .'turned back on, it needs a way to create a department_limits row first — '
                .'without one this job resets nothing, exactly as it did for 33 rounds.',
            );

            $this->assertStringNotContainsString(
                'reset-department-limits',
                $summary,
                'The department-limit reset is scheduled again.',
            );
        }
    }
}
