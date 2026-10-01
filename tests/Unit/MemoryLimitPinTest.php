<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The memory ceiling the suite runs under, pinned to what phpunit.xml says.
 *
 * The full suite at the CLI default of 128M died on 2026-08-12 — not in some
 * huge export, but in a one-order workbook: zipstream-php reads every zip
 * entry through fread() with a 16 MiB chunk (CHUNKED_READ_BLOCK_SIZE,
 * File.php:334), and PHP allocates the whole requested chunk before reading,
 * so the last 16 MiB of headroom vanish on whichever test happens to write an
 * XLSX after a few hundred application boots have eaten the rest. The archive
 * itself was never in memory — Laravel Excel saves to a temp file — which is
 * why the failure moved with test order rather than workbook size.
 *
 * phpunit.xml therefore pins memory_limit=512M in <php><ini>, and every
 * runner applies that: artisan test, vendor/bin/phpunit, and CI — where
 * setup-php defaults memory_limit to -1, a ceiling that can never catch a
 * memory regression. This test guards the pin: drop the ini line, or run the
 * suite through something that ignores phpunit.xml, and this fails before the
 * flake gets a chance to.
 */
class MemoryLimitPinTest extends TestCase
{
    public function test_the_suite_runs_with_the_memory_limit_phpunit_xml_pins(): void
    {
        $this->assertSame(
            '512M',
            ini_get('memory_limit'),
            'phpunit.xml pins memory_limit=512M; this process runs with "'
            .ini_get('memory_limit')
            .'". Either the <ini> line was dropped from phpunit.xml or this runner ignored it.',
        );
    }
}
