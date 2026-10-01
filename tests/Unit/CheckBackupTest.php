<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Tests\TestCase;

/**
 * backup:check-health — the only thing that says the backups have stopped.
 *
 * 36 instructions at 0% through seven rounds. It runs at 05:00, an hour after
 * the dump, and its whole job is to make a silence audible.
 */
class CheckBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id'   => '123456',
        ]);

        $disk = config('backup.backup.destination.disks')[0] ?? 'local';
        $root = config("filesystems.disks.{$disk}.root", storage_path('app'));
        $name = config('backup.backup.name', config('app.name', 'laravel-backup'));

        $this->backupDir = $root.'/'.$name;

        if (! is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0777, true);
        }
        $this->clearBackups();
    }

    protected function tearDown(): void
    {
        $this->clearBackups();
        parent::tearDown();
    }

    private function clearBackups(): void
    {
        foreach (glob($this->backupDir.'/*.{zip,sql,gz}', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
    }

    private function backupAgedHours(float $hours): string
    {
        $path = $this->backupDir.'/test-backup.zip';

        // Until round 28 this line was `file_put_contents($path, 'dump')` —
        // four bytes named `.zip`, and every assertion below called it a
        // healthy backup. The fixture measured what the command could see.
        $this->writeArchive($path, $this->completeDump());
        touch($path, time() - (int) round($hours * 3600));

        return $path;
    }

    /**
     * The shape `backup:run --only-db` actually writes, taken from an archive
     * it produced: one entry, `db-dumps/postgresql-<database>.sql`, holding a
     * plain pg_dump between its own header and its completion line.
     */
    private function writeArchive(
        string $path,
        ?string $dump,
        int $fillerBytes = 0,
        string $entry = 'db-dumps/postgresql-crm_print.sql',
    ): void {
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($dump !== null) {
            $zip->addFromString($entry, $dump);
        }

        if ($fillerBytes > 0) {
            $zip->addFromString('filler.bin', str_repeat('x', $fillerBytes));
            $zip->setCompressionName('filler.bin', \ZipArchive::CM_STORE);
        }

        $zip->close();
    }

    private function completeDump(): string
    {
        return "--\n-- PostgreSQL database dump\n--\n\n"
             ."SET statement_timeout = 0;\n"
             ."CREATE TABLE public.orders (id integer NOT NULL);\n\n"
             ."--\n-- PostgreSQL database dump complete\n--\n";
    }

    private function telegramCalls(): int
    {
        $count = 0;
        Http::recorded(function ($request) use (&$count) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                $count++;
            }

            return true;
        });

        return $count;
    }

    public function test_a_fresh_backup_passes_quietly(): void
    {
        $this->backupAgedHours(1);

        $this->artisan('backup:check-health')->assertExitCode(0);

        $this->assertSame(0, $this->telegramCalls(), 'A healthy backup must not send anything.');
    }

    public function test_a_stale_backup_fails_and_alerts(): void
    {
        $this->backupAgedHours(30);

        $this->artisan('backup:check-health')->assertExitCode(1);

        $this->assertSame(1, $this->telegramCalls());
    }

    public function test_no_backup_at_all_fails_and_alerts(): void
    {
        $this->artisan('backup:check-health')->assertExitCode(1);

        $this->assertSame(1, $this->telegramCalls());
    }

    /**
     * The design decision is written down in bootstrap/app.php: the
     * `telegram_enabled` setting governs operational notifications — shifts,
     * orders, withdrawals — and "system error monitoring must ALWAYS work
     * regardless of operator preferences". Error alerts honour that by
     * bypassing TelegramService::send().
     *
     * The backup alarm did not. It went through send(), which the setting
     * gates, so an admin turning off operational noise also turned off the one
     * signal that says the backups have stopped. The exit code is no fallback:
     * the scheduler's output goes to /dev/null on this server.
     */
    public function test_the_backup_alarm_is_not_silenced_by_the_operational_toggle(): void
    {
        Setting::setValue('telegram_enabled', false);
        $this->backupAgedHours(30);

        $this->artisan('backup:check-health')->assertExitCode(1);

        $this->assertSame(
            1,
            $this->telegramCalls(),
            'Turning off operational notifications also turned off the backup alarm.',
        );
    }

    /**
     * Operational notifications must still obey the toggle — the point is that
     * the two are different kinds of message, not that the setting is ignored.
     */
    public function test_operational_notifications_still_obey_the_toggle(): void
    {
        Setting::setValue('telegram_enabled', false);

        app(TelegramService::class)->shiftOpened('Оператор', 42);

        $this->assertSame(0, $this->telegramCalls());
    }

    // ─── Storage ceiling ─────────────────────────
    //
    // config/backup.php has declared MaximumStorageInMegabytes since the start,
    // but only `backup:monitor` reads it and that command is scheduled nowhere.
    // Nothing had ever looked at how large this directory is — and retention is
    // switched off on purpose, so it only grows.

    public function test_a_backup_directory_over_the_ceiling_fails_and_alerts(): void
    {
        $this->setStorageLimitMb(1);
        $this->backupOfSizeMb('big-backup.zip', 2);

        $this->artisan('backup:check-health')->assertExitCode(1);

        $this->assertSame(1, $this->telegramCalls());
    }

    public function test_a_backup_directory_under_the_ceiling_passes_quietly(): void
    {
        $this->setStorageLimitMb(50);
        $this->backupOfSizeMb('small-backup.zip', 1);

        $this->artisan('backup:check-health')->assertExitCode(0);

        $this->assertSame(0, $this->telegramCalls());
    }

    public function test_the_ceiling_counts_every_archive_not_just_the_newest(): void
    {
        // Retention being off is exactly why the total matters: no single file
        // is large, and the directory still grows past the limit.
        $this->setStorageLimitMb(3);
        $this->backupOfSizeMb('one.zip', 2);
        $this->backupOfSizeMb('two.zip', 2);

        $this->artisan('backup:check-health')->assertExitCode(1);
    }

    private function setStorageLimitMb(int $mb): void
    {
        config([
            'backup.monitor_backups.0.health_checks' => [
                MaximumStorageInMegabytes::class => $mb,
            ],
        ]);
    }

    private function backupOfSizeMb(string $name, int $mb): void
    {
        // A real archive of the requested size, not a megabyte of `x` named
        // `.zip`: since round 28 an unreadable archive fails before the
        // ceiling is ever weighed, so a fake fixture would have made these
        // three tests pass for the wrong reason.
        $path = $this->backupDir.'/'.$name;
        $this->writeArchive($path, $this->completeDump(), $mb * 1048576);
        touch($path, time() - 3600);
    }

    public function test_a_backup_in_a_subdirectory_is_found(): void
    {
        $sub = $this->backupDir.'/2026-07';
        if (! is_dir($sub)) {
            mkdir($sub, 0777, true);
        }

        $path = $sub.'/nested-backup.zip';
        $this->writeArchive($path, $this->completeDump());
        touch($path, time() - 3600);

        $this->artisan('backup:check-health')->assertExitCode(0);

        @unlink($path);
        @rmdir($sub);
    }

    // ─── Integrity ───────────────────────────────
    //
    // A recent file is not a usable backup. A real archive from
    // `backup:run --only-db`, truncated to half its length, used to print
    // «Backup OK»; these tests keep that from coming back.

    public function test_an_archive_that_will_not_open_fails_and_alerts(): void
    {
        $path = $this->backupDir.'/truncated.zip';
        $this->writeArchive($path, $this->completeDump());

        // Cut it in half: the zip central directory lives at the end, so what
        // is left is a file that looks right by name, date and size.
        $whole = (string) file_get_contents($path);
        file_put_contents($path, substr($whole, 0, (int) (strlen($whole) / 2)));
        touch($path, time() - 3600);

        $this->artisan('backup:check-health')->assertExitCode(1);

        $this->assertSame(1, $this->telegramCalls(), 'A backup nobody could restore from passed as healthy.');
    }

    public function test_an_archive_with_no_dump_inside_fails_and_alerts(): void
    {
        $path = $this->backupDir.'/no-dump.zip';
        $this->writeArchive($path, null, 1024);
        touch($path, time() - 3600);

        $this->artisan('backup:check-health')->assertExitCode(1);

        $this->assertSame(1, $this->telegramCalls());
    }

    public function test_an_empty_dump_inside_a_valid_archive_fails_and_alerts(): void
    {
        // pg_dump exiting on an error leaves a file behind; zip is happy to
        // carry it, and the mtime is as fresh as any other night's.
        $path = $this->backupDir.'/empty-dump.zip';
        $this->writeArchive($path, '');
        touch($path, time() - 3600);

        $this->artisan('backup:check-health')->assertExitCode(1);

        $this->assertSame(1, $this->telegramCalls());
    }

    /**
     * The dangerous one: the archive opens, the dump inside is large and looks
     * entirely plausible — and it stops mid-table, because the disk filled or
     * the connection dropped. Only the last line pg_dump writes can tell.
     */
    public function test_a_dump_that_stops_halfway_fails_and_alerts(): void
    {
        $path = $this->backupDir.'/half-dump.zip';
        $this->writeArchive(
            $path,
            "--\n-- PostgreSQL database dump\n--\n\nCOPY public.orders (id) FROM stdin;\n1\t2\n",
        );
        touch($path, time() - 3600);

        $this->artisan('backup:check-health')->assertExitCode(1);

        $this->assertSame(1, $this->telegramCalls());
    }

    public function test_a_file_that_is_not_a_dump_at_all_fails_and_alerts(): void
    {
        $path = $this->backupDir.'/wrong-content.zip';
        $this->writeArchive($path, "SELECT 1;\n");
        touch($path, time() - 3600);

        $this->artisan('backup:check-health')->assertExitCode(1);

        $this->assertSame(1, $this->telegramCalls());
    }

    public function test_a_plain_sql_dump_is_read_the_same_way(): void
    {
        $path = $this->backupDir.'/plain.sql';
        file_put_contents($path, $this->completeDump());
        touch($path, time() - 3600);

        $this->artisan('backup:check-health')->assertExitCode(0);

        file_put_contents($path, "--\n-- PostgreSQL database dump\n--\n\nCOPY public.orders");
        touch($path, time() - 3600);

        $this->artisan('backup:check-health')->assertExitCode(1);
    }

    /**
     * Nothing here writes a `.gz` — the dump compressor is null and the zip is
     * the archive. If one ever appears, the command must not pretend it looked
     * inside: an unchecked file that prints a bare «OK» is the defect this
     * whole round is about.
     */
    public function test_a_format_it_cannot_open_is_reported_as_unchecked_not_as_healthy(): void
    {
        $path = $this->backupDir.'/dump.gz';
        file_put_contents($path, 'not really gzip');
        touch($path, time() - 3600);

        $this->artisan('backup:check-health')
            ->expectsOutputToContain('not checked')
            ->assertExitCode(0);
    }
}
