<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;
use ZipArchive;

/**
 * CheckBackup
 *
 * Verifies that a recent database backup exists — and, since round 28, that
 * the newest archive actually contains a complete dump.
 *
 * Sends Telegram alert if backup is older than 24 hours, if the newest archive
 * cannot be opened or holds no finished dump, or if the directory has grown
 * past the declared ceiling.
 *
 * Alerts go through sendCritical(), not send(): this is the system reporting
 * on itself, and it must not be switchable off along with shift and order
 * notifications — see TelegramService::sendCritical().
 */
class CheckBackup extends Command
{
    protected $signature = 'backup:check-health';

    protected $description = 'Check that a recent, readable backup exists, alert via Telegram if not';

    /**
     * pg_dump writes both of these, and it has done so for every major version
     * this project could meet. Taken from the tool that produces them, not from
     * documentation: `backup:run --only-db` was executed against a real
     * database and the archive it wrote was opened and read.
     *
     * The second one is the whole point. It is the last thing pg_dump writes,
     * so a dump that carries it is a dump that finished.
     */
    private const DUMP_HEADER = 'PostgreSQL database dump';

    private const DUMP_FOOTER = 'PostgreSQL database dump complete';

    public function __construct(
        private readonly TelegramService $telegram,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $diskName = config('backup.backup.destination.disks')[0] ?? 'local';
        $diskRoot = config("filesystems.disks.{$diskName}.root", storage_path('app'));
        $appName = config('backup.backup.name', config('app.name', 'laravel-backup'));
        $backupDir = $diskRoot.'/'.$appName;

        // Find newest .sql or .zip file in backup directory (recursive)
        $newest = null;
        $newestTime = 0;
        $totalBytes = 0;
        $archives = 0;

        $this->scanDir($backupDir, $newest, $newestTime, $totalBytes, $archives);

        if (! $newest) {
            $this->telegram->sendCritical("🔴 *Backup Alert*\n\nНе знайдено жодного бекапу!\nПеревірте `{$backupDir}`");
            $this->error('No backup files found!');

            return self::FAILURE;
        }

        $age = time() - $newestTime;
        $ageHours = round($age / 3600, 1);

        if ($age > 86400) { // > 24 hours
            $this->telegram->sendCritical(
                "⚠️ *Backup Alert*\n\n"
              ."Останній бекап: *{$ageHours}h* тому\n"
              .'Файл: `'.basename($newest)."`\n"
              .'Перевірте cron scheduler!'
            );
            $this->warn("Backup is {$ageHours}h old: {$newest}");

            return self::FAILURE;
        }

        // Integrity.
        //
        // A recent file is not the same as a usable backup, so the archive is
        // opened and the dump inside it is checked as well.
        //
        // Proven by action rather than by reading: a real archive produced by
        // `backup:run --only-db`, truncated to half its length so the zip
        // central directory was gone entirely, still passed as "Backup OK".
        // The alarm that exists to make a silence audible was itself silent
        // about the one thing that makes a backup worth having.
        $verdict = $this->inspect($newest);

        if (str_starts_with($verdict, 'broken:')) {
            $this->telegram->sendCritical(
                "🔴 *Backup Alert*\n\n"
              ."Останній бекап не годиться для відновлення:\n"
              .$this->reasonInUkrainian($verdict)."\n"
              .'Файл: `'.basename($newest)."`\n"
              ."Вік: *{$ageHours}h* — тобто розклад працює, а вміст ні."
            );
            $this->error("Backup is unusable ({$verdict}): {$newest}");

            return self::FAILURE;
        }

        // Storage ceiling. config/backup.php declares MaximumStorageInMegabytes,
        // but only `backup:monitor` reads it and that command is scheduled
        // nowhere — so nothing has ever checked how big this directory is
        //. Checking it here rather than scheduling backup:monitor
        // avoids two alarms about the same thing: monitor would re-run the age
        // check this command already does, from a second channel.
        //
        // It matters more than it looks: retention (`backup:clean`) is switched
        // off on purpose — the policy would delete 80-90 of ~100 archives to
        // reclaim ~10 MB — so this directory only ever grows.
        $limitMb = $this->storageLimitMb();
        $totalMb = round($totalBytes / 1048576, 1);

        if ($limitMb !== null && $totalMb > $limitMb) {
            $this->telegram->sendCritical(
                "⚠️ *Backup Alert*\n\n"
              ."Сховище бекапів: *{$totalMb} MB* (ліміт {$limitMb} MB)\n"
              ."Архівів: {$archives}\n"
              ."`{$backupDir}`\n"
              .'Очистка вимкнена свідомо — потрібне рішення власника.'
            );
            $this->warn("Backup storage {$totalMb}MB exceeds {$limitMb}MB");

            return self::FAILURE;
        }

        // The success line names what was checked, not just that it passed.
        // «OK» that does not say what it looked at is how a gate comes to be
        // read as an answer to a question it never asked (C-1, R8-2, R22-6).
        //
        // The archive count is here because retention is off on purpose: how
        // much room is left is a question somebody will ask under pressure the
        // day the ceiling alarm fires, and this is the cheapest place to have
        // been answering it all along.
        $this->info(
            "Backup OK: {$ageHours}h old, {$archives} archives, {$totalMb}MB total, "
          .$this->verdictInEnglish($verdict).' — '.basename($newest)
        );

        return self::SUCCESS;
    }

    /**
     * Look inside the archive. Returns `ok`, `skipped:<why>` or `broken:<what>`.
     *
     * `skipped` is not `ok` and is not silence: the reason is printed on the
     * success line, so a directory this command cannot read never looks like a
     * directory it has approved.
     */
    private function inspect(string $path): string
    {
        if (preg_match('/\.sql$/i', $path)) {
            $dump = @file_get_contents($path, false, null, 0, 4096);
            $tail = $this->tailOfFile($path);

            return $dump === false
                ? 'broken:unreadable'
                : $this->judgeDump($dump, $tail);
        }

        if (! preg_match('/\.zip$/i', $path)) {
            // A `.gz` is the one other extension the scanner accepts. Nothing
            // in this project writes one — `database_dump_compressor` is null
            // and the zip is the archive — so rather than guess at a format
            // that has never appeared here, say plainly that it was not read.
            return 'skipped:not-a-zip';
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return 'broken:unreadable';
        }

        $entry = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat !== false && preg_match('/\.sql$/i', (string) $stat['name'])) {
                $entry = $stat;
                break;
            }
        }

        if ($entry === null) {
            $zip->close();

            return 'broken:no-dump';
        }

        if ((int) $entry['size'] === 0) {
            $zip->close();

            return 'broken:empty-dump';
        }

        $stream = $zip->getStream((string) $entry['name']);

        if ($stream === false) {
            $zip->close();

            return 'broken:unreadable';
        }

        // Read the whole entry but keep only both ends: the dump is ~180 KB
        // today and this must not become the reason the 05:00 task falls over
        // on a database that has grown.
        $head = '';
        $tail = '';

        while (! feof($stream)) {
            $chunk = fread($stream, 65536);

            if ($chunk === false) {
                break;
            }

            if ($head === '') {
                $head = substr($chunk, 0, 4096);
            }

            $tail = substr($tail.$chunk, -4096);
        }

        fclose($stream);
        $zip->close();

        return $this->judgeDump($head, $tail);
    }

    /**
     * Both ends of a PostgreSQL dump, in the order pg_dump writes them.
     */
    private function judgeDump(string $head, string $tail): string
    {
        if (! str_contains($head, self::DUMP_HEADER)) {
            return 'broken:not-a-dump';
        }

        if (! str_contains($tail, self::DUMP_FOOTER)) {
            return 'broken:truncated';
        }

        return 'ok';
    }

    private function tailOfFile(string $path): string
    {
        $size = (int) filesize($path);
        $from = max(0, $size - 4096);

        return (string) @file_get_contents($path, false, null, $from, 4096);
    }

    private function verdictInEnglish(string $verdict): string
    {
        return match ($verdict) {
            'ok'                => 'dump complete',
            'skipped:not-a-zip' => 'contents not checked (not a zip)',
            default             => $verdict,
        };
    }

    private function reasonInUkrainian(string $verdict): string
    {
        return match ($verdict) {
            'broken:unreadable' => 'архів не відкривається — файл пошкоджений або обірваний',
            'broken:no-dump'    => 'усередині архіву немає дампа бази (`.sql`)',
            'broken:empty-dump' => 'дамп усередині архіву порожній',
            'broken:not-a-dump' => 'файл усередині не схожий на дамп PostgreSQL',
            'broken:truncated'  => 'дамп обірваний — немає рядка про завершення `pg_dump`',
            default             => $verdict,
        };
    }

    /**
     * The ceiling declared in config/backup.php, or null if none is configured.
     */
    private function storageLimitMb(): ?int
    {
        $checks = config('backup.monitor_backups.0.health_checks', []);

        foreach ($checks as $check => $value) {
            if (is_string($check) && str_contains($check, 'MaximumStorageInMegabytes')) {
                return (int) $value;
            }
        }

        return null;
    }

    private function scanDir(string $dir, ?string &$newest, int &$newestTime, int &$totalBytes, int &$archives): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;

            if (is_dir($path)) {
                $this->scanDir($path, $newest, $newestTime, $totalBytes, $archives);
            } elseif (preg_match('/\.(sql|zip|gz)$/i', $item)) {
                $totalBytes += (int) filesize($path);
                $archives++;

                $mtime = filemtime($path);
                if ($mtime > $newestTime) {
                    $newestTime = $mtime;
                    $newest = $path;
                }
            }
        }
    }
}
