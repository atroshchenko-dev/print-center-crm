<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exports\BackdatedOrdersCategorySheet;
use App\Exports\BackdatedOrdersExport;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Sheet titles in the two accounting workbooks (R12-1... see R12-2 in the
 * register).
 *
 * The tab name comes from a short-name map covering the seeded services;
 * anything an admin adds falls through to the raw service name. PhpSpreadsheet
 * refuses `* : / \ ? [ ]` outright, so one such service turned the whole
 * download into a 500 — and not hypothetically: the seeded "Дипломи/Додатки"
 * already carries a slash and survives only because the map rewrites it.
 *
 * The assertions run the title through PhpSpreadsheet itself rather than
 * restating its rule, so they keep testing the real constraint if it changes.
 */
class ExportSheetTitleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // BackdatedOrdersCategorySheet is declared inside BackdatedOrdersExport.php,
        // so PSR-4 cannot autoload it under its own name. Touching the file's
        // primary class pulls both in.
        class_exists(BackdatedOrdersExport::class);
    }

    /**
     * @return list<array{string}>
     */
    public static function forbiddenNames(): array
    {
        return [
            ['Друк А4/А3'],
            ['Ламінування*термо'],
            ['Скан: кольоровий'],
            ['Друк \\ копії'],
            ['Брошура?зшивка'],
            ['Палітурка [тверда]'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenNames')]
    public function test_a_service_name_excel_forbids_still_produces_a_usable_tab(string $name): void
    {
        $title = (new BackdatedOrdersCategorySheet($name, collect()))->title();

        // The real check: PhpSpreadsheet throws if the title is unusable.
        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->setTitle($title);

        $this->assertSame($title, $sheet->getTitle());
    }

    public function test_a_very_long_service_name_is_cut_to_the_excel_limit(): void
    {
        $title = (new BackdatedOrdersCategorySheet(str_repeat('Друк ', 20), collect()))->title();

        $this->assertLessThanOrEqual(31, mb_strlen($title));

        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->setTitle($title);
    }

    public function test_the_seeded_names_still_use_their_short_tab(): void
    {
        // The map is the reason 'Дипломи/Додатки' never broke anything; sanitising
        // must not take over names it already covers.
        $this->assertSame(
            'Дипломи',
            (new BackdatedOrdersCategorySheet('Дипломи/Додатки', collect()))->title(),
        );
        $this->assertSame(
            'ЧБ',
            (new BackdatedOrdersCategorySheet('Чорно-білий друк', collect()))->title(),
        );
    }
}
