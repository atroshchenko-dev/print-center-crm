<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exports\BackdatedOrdersCategorySheet;
use App\Exports\BackdatedOrdersExport;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\OrderItem;
use App\Models\RisoPriceTier;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Models\User;
use App\Services\BrochurePricingService;
use App\Services\ConstructorPricingService;
use App\Services\DiplomaPricingService;
use App\Services\RisoPricingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * BackdatedOrdersExportTest — the accounting spreadsheet.
 *
 * The Exports/ classes sat at 3–4% coverage, which is why upgrading
 * phpoffice/phpspreadsheet on 2026-07-27 had to be verified by hand on
 * production: nothing in the suite would have caught a broken export.
 *
 * This is also the most intricate export in the project. Every service type
 * gets its own sheet with its own column layout, and each row is reconstructed
 * from the JSONB `service_snapshot` — so a snapshot shape drifting away from
 * what the mapper expects produces a quietly wrong report rather than an error.
 *
 * The tests work on the sheet objects directly rather than the rendered file:
 * that is where the mapping logic lives, and it keeps them fast.
 */
class BackdatedOrdersExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /**
     * The third place with this defect, and the reason it is worth a test here
     * rather than a one-line fix.
     *
     * dateRange() returns Kyiv midnights as UTC instants; formatting one into a
     * file name puts the start of the range on the previous date. The report
     * exports were fixed for it, the reconciliation export was found in round 9
     * — and this one was still building the name by hand a few files away.
     * Fixing the place instead of the rule is what let it survive.
     */
    public function test_the_export_is_named_after_the_kyiv_dates_that_were_asked_for(): void
    {
        Excel::fake();

        $this->actingAs($this->admin)
            ->get(route('admin.backdated-orders.export', ['from' => '2026-07-01', 'to' => '2026-07-31']))
            ->assertOk();

        Excel::assertDownloaded('retro-orders-20260701-20260731.xlsx');
    }

    public function test_the_export_without_a_range_is_named_all(): void
    {
        Excel::fake();

        $this->actingAs($this->admin)
            ->get(route('admin.backdated-orders.export'))
            ->assertOk();

        Excel::assertDownloaded('retro-orders-all.xlsx');
    }

    /**
     * A retro internal order with one item of the given service.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $itemAttributes
     */
    private function retroOrder(
        string $serviceName,
        array $snapshot = [],
        array $itemAttributes = [],
        array $orderAttributes = [],
    ): Order {
        $order = Order::factory()->create(array_merge([
            'user_id'           => $this->admin->id,
            'shift_id'          => null,
            'type'              => OrderType::Internal,
            'status'            => OrderStatus::CompletedIssued,
            'is_backdated'      => true,
            'authorized_person' => 'Алчук В.Г.',
            'cost_center'       => 'Кафедра тестування',
            'created_at'        => Carbon::parse('2026-06-15 10:00:00'),
            'total_cost'        => 12.50,
        ], $orderAttributes));

        OrderItem::factory()->create(array_merge([
            'order_id'         => $order->id,
            'service_name'     => $serviceName,
            'quantity'         => 5,
            'unit_price_cost'  => 2.50,
            'total_price_cost' => 12.50,
            'service_snapshot' => $snapshot,
        ], $itemAttributes));

        return $order->fresh(['items']);
    }

    /** The sheets an export produces, keyed by title. */
    private function sheetsByTitle(BackdatedOrdersExport $export): array
    {
        $byTitle = [];
        foreach ($export->sheets() as $sheet) {
            $byTitle[$sheet->title()] = $sheet;
        }

        return $byTitle;
    }

    // ─── Which sheets appear ────────────────────────────────────────

    public function test_each_service_gets_its_own_sheet_plus_a_summary(): void
    {
        $this->retroOrder('Чорно-білий друк');
        $this->retroOrder('Сканування');

        $titles = array_keys($this->sheetsByTitle(new BackdatedOrdersExport));

        $this->assertContains('ЧБ', $titles, 'Long names are shortened for the 31-char tab limit');
        $this->assertContains('Скан', $titles);
        $this->assertCount(3, $titles, 'Two services and the summary');
    }

    public function test_only_backdated_internal_orders_are_exported(): void
    {
        $this->retroOrder('Сканування');
        $this->retroOrder('Сканування', orderAttributes: ['is_backdated' => false]);
        $this->retroOrder('Сканування', orderAttributes: ['type' => OrderType::Commercial]);

        $sheet = $this->sheetsByTitle(new BackdatedOrdersExport)['Скан'];

        $this->assertCount(
            1,
            $sheet->collection(),
            'Live orders and commercial ones belong to other reports',
        );
    }

    public function test_cancelled_orders_are_left_out(): void
    {
        $this->retroOrder('Сканування');
        $this->retroOrder('Сканування', orderAttributes: ['status' => OrderStatus::Cancelled]);

        $sheet = $this->sheetsByTitle(new BackdatedOrdersExport)['Скан'];

        $this->assertCount(1, $sheet->collection());
    }

    public function test_the_date_range_is_honoured(): void
    {
        $this->retroOrder('Сканування', orderAttributes: [
            'created_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);
        $this->retroOrder('Сканування', orderAttributes: [
            'created_at' => Carbon::parse('2026-05-01 10:00:00'),
        ]);

        $export = new BackdatedOrdersExport(
            from: Carbon::parse('2026-06-01'),
            to: Carbon::parse('2026-06-30 23:59:59'),
        );

        $this->assertCount(1, $this->sheetsByTitle($export)['Скан']->collection());
    }

    public function test_the_reconciled_filter_selects_one_side_only(): void
    {
        $this->retroOrder('Сканування', orderAttributes: ['is_reconciled' => true]);
        $this->retroOrder('Сканування', orderAttributes: ['is_reconciled' => false]);

        $this->assertCount(
            1,
            $this->sheetsByTitle(new BackdatedOrdersExport(reconciled: true))['Скан']->collection(),
        );
        $this->assertCount(
            1,
            $this->sheetsByTitle(new BackdatedOrdersExport(reconciled: false))['Скан']->collection(),
        );
    }

    /**
     * Which confirmation the accountant will be looking for.
     *
     * Both paths set the same `request_received`, so a column that only says
     * «Отримано» sends them hunting through the folder for a signature that
     * was only ever a click. See Tests\Feature\RequestSourceTest for the rule
     * itself; this pins that the workbook repeats it.
     */
    public function test_the_workbook_says_which_confirmation_arrived(): void
    {
        $letter = $this->retroOrder('Сканування', orderAttributes: ['request_received' => true]);
        $this->retroOrder('Ламінування', orderAttributes: ['request_received' => true]);
        $this->retroOrder('Дипломи/Додатки', orderAttributes: ['request_received' => false]);

        OrderApproval::create([
            'order_id'        => $letter->id,
            'token'           => bin2hex(random_bytes(24)),
            'signatory_email' => 'signatory@example.test',
            'signatory_name'  => 'Тестовий Підписант',
            'status'          => 'approved',
            'expires_at'      => now()->addHours(72),
        ]);

        $sheets = $this->sheetsByTitle(new BackdatedOrdersExport);

        $rowOf = fn (string $title) => $sheets[$title]->map($sheets[$title]->collection()->first());

        $this->assertContains('Отримано (лист)', $rowOf('Скан'));
        $this->assertContains('Отримано (папір)', $rowOf('Ламінація'));
        $this->assertContains('—', $rowOf('Дипломи'));
    }

    // ─── Row mapping ────────────────────────────────────────────────

    public function test_a_print_row_carries_the_order_details_and_its_parameters(): void
    {
        $this->retroOrder('Чорно-білий друк', snapshot: [
            'constructor_snapshot' => [
                ['group_name' => 'Формат', 'option_name' => 'А4'],
                ['group_name' => ServiceParameterGroup::PAPER, 'option_name' => 'А4: Офсетний 80'],
                ['group_name' => 'Сторонність', 'option_name' => 'Двосторонній: 1+1'],
            ],
        ], itemAttributes: ['bw_clicks' => 10]);

        $sheet = $this->sheetsByTitle(new BackdatedOrdersExport)['ЧБ'];
        $row = $sheet->map($sheet->collection()->first());

        $this->assertSame('15.06.2026', $row[1], 'The date shown must be the backdated one');
        $this->assertSame('Алчук В.Г.', $row[2]);
        $this->assertSame('Кафедра тестування', $row[3]);
        $this->assertSame('А4', $row[4], 'Формат');
        $this->assertSame('Офсетний 80', $row[5], 'The А4:/А3: prefix is stripped from the paper');
        $this->assertSame('1+1', $row[6], 'Режим — taken from the tail of "Двосторонній: 1+1"');
        $this->assertSame(10, $row[7], 'Кліки Ч/Б');
        $this->assertSame(5, $row[9], 'Кількість');
        $this->assertCount(count($sheet->headings()), $row, 'Row width must match the headings');
    }

    /**
     * The one rule in the whole export that depends on a group being named
     * exactly right (audit finding L-6): when the customer brings paper, the
     * paper column must say so rather than naming a stock item.
     */
    public function test_customer_paper_is_reported_as_such(): void
    {
        $this->retroOrder('Чорно-білий друк', snapshot: [
            'customer_paper'       => true,
            'constructor_snapshot' => [
                ['group_name' => 'Формат', 'option_name' => 'А4'],
                ['group_name' => ServiceParameterGroup::PAPER, 'option_name' => 'А4: Офсетний 80'],
            ],
        ]);

        $sheet = $this->sheetsByTitle(new BackdatedOrdersExport)['ЧБ'];
        $row = $sheet->map($sheet->collection()->first());

        $this->assertSame('Замовника', $row[5]);
    }

    public function test_a_missing_snapshot_leaves_dashes_rather_than_blowing_up(): void
    {
        $this->retroOrder('Чорно-білий друк', snapshot: []);

        $sheet = $this->sheetsByTitle(new BackdatedOrdersExport)['ЧБ'];
        $row = $sheet->map($sheet->collection()->first());

        $this->assertSame('—', $row[4], 'An empty snapshot must not produce an empty column');
        $this->assertCount(count($sheet->headings()), $row);
    }

    public function test_the_request_and_reconciliation_columns_read_as_words(): void
    {
        $this->retroOrder('Сканування', orderAttributes: [
            'request_received' => true,
            'is_reconciled'    => false,
        ]);

        $sheet = $this->sheetsByTitle(new BackdatedOrdersExport)['Скан'];
        $row = $sheet->map($sheet->collection()->first());

        // The column names the confirmation, not just its presence — see
        // test_the_workbook_says_which_confirmation_arrived above.
        $this->assertContains('Отримано (папір)', $row);
        $this->assertContains('Очікує', $row);
    }

    public function test_the_material_note_reaches_the_report(): void
    {
        $this->retroOrder('Сканування', itemAttributes: [
            'material_description' => 'Дипломи 2026, 3 курс',
        ]);

        $sheet = $this->sheetsByTitle(new BackdatedOrdersExport)['Скан'];

        $this->assertContains('Дипломи 2026, 3 курс', $sheet->map($sheet->collection()->first()));
    }

    // ─── The snapshot writers against the sheet readers ─────────────

    /**
     * Eight category mappers read `service_snapshot` by key, and until now every
     * test here handed them a snapshot written by the test itself. That proves
     * the mapper reads what the test writes; it says nothing about whether the
     * pricing services write what the mapper reads, and those two sides had
     * never been put in the same room. A key renamed on the writing side would
     * show up as an empty cell in the accounting workbook — a dash where a
     * paper name should be — and nothing would throw.
     *
     * So these build the snapshot with the real service and then map it.
     *
     * `Excel::fake()` cannot do this: it never calls sheets(), collection() or
     * map(). Neither can a factory snapshot. The only way to cross this boundary
     * is to have one side actually produce what the other consumes.
     */
    public function test_a_riso_row_is_filled_from_a_snapshot_the_riso_service_wrote(): void
    {
        RisoPriceTier::query()->forceDelete();
        RisoPriceTier::create(['min_qty' => 1, 'max_qty' => null, 'cost_per_copy' => 0.50]);

        $paper = $this->paper(config('riso.default_paper_a3_name'), 1.20);

        $snapshot = app(RisoPricingService::class)->buildSnapshot(
            $this->service('Тиражування (RISO)', 'riso'),
            copies: 30, format: 'A3', sides: 2, paperId: $paper->id, originals: 3,
        );

        $row = $this->mapOne('Тиражування (RISO)', $snapshot, ['riso_clicks' => 60]);

        $this->assertSame('A3', $row[4], 'Формат');
        $this->assertSame('1+1', $row[5], 'Сторонність');
        $this->assertSame(30, $row[6], 'Аркушів А3');
        $this->assertSame('1+', $row[7], 'Тарифна група');
        $this->assertSame('0.5000', $row[8], 'Вартість кліку');
        $this->assertSame($paper->name, $row[9], 'Папір');
        $this->assertSame(60, $row[10], 'Кліки Різо');
    }

    public function test_a_brochure_row_is_filled_from_a_snapshot_the_brochure_service_wrote(): void
    {
        $this->clickCosts();
        $cover = $this->paper('Папір А3 160 г/м²', 2.00);
        $block = $this->paper('Папір А3 80 г/м²', 0.90);

        $snapshot = app(BrochurePricingService::class)->buildSnapshot(
            $this->service('Брошура (скоба)', 'brochure'),
            [
                'format'         => 'А4',
                'cover_paper_id' => $cover->id,
                'cover_mode'     => '4+0',
                'block_paper_id' => $block->id,
                'block_entries'  => [['mode' => '1+0', 'sheets' => 5], ['mode' => '4+0', 'sheets' => 2]],
            ],
            quantity: 10,
        );

        $row = $this->mapOne('Брошура (скоба)', $snapshot);

        $this->assertSame('А4', $row[4], 'Формат');
        $this->assertSame($cover->name, $row[5], 'Обкл. папір');
        $this->assertSame('4+0', $row[6], 'Обкл. режим');
        $this->assertSame($block->name, $row[7], 'Блок папір');
        $this->assertSame(7, $row[8], 'Блок аркушів');
        $this->assertSame('1+0 (5 арк), 4+0 (2 арк)', $row[9], 'Блок режим(и)');
    }

    public function test_a_diploma_row_is_filled_from_a_snapshot_the_diploma_service_wrote(): void
    {
        $this->clickCosts();
        $this->paper('Папір А4 160 г/м²', 1.60);
        $this->paper('Папір А3 160 г/м²', 3.20);
        $this->paper('Папір А4 80 г/м²', 0.50);

        $snapshot = app(DiplomaPricingService::class)->buildSnapshot(
            $this->service('Дипломи/Додатки', 'diploma'),
            [
                'diplomas'    => ['qty' => 12],
                'supplements' => [[
                    'type'   => 'bachelor',
                    'label'  => 'Бакалавр',
                    'qty'    => 2,
                    'blocks' => [['sheets' => 4, 'mode' => '4+4'], ['sheets' => 1, 'mode' => '4+0']],
                ]],
                'academic_records' => ['qty' => 3],
                'copies'           => [
                    'diploma_copies'    => ['qty' => 7, 'mode' => '1+0'],
                    'supplement_copies' => ['qty' => 2, 'blocks' => [['sheets' => 6, 'mode' => '1+1']]],
                ],
            ],
            quantity: 1,
        );

        $row = $this->mapOne('Дипломи/Додатки', $snapshot);

        $this->assertSame(12, $row[4], 'Дипломів');
        $this->assertSame(10, $row[5], 'Додатків (арк) — 2 sets × 5 sheets');
        $this->assertSame(3, $row[6], 'Академдовідок');
        $this->assertSame(7, $row[7], 'Копій дипл.');
        $this->assertSame(12, $row[8], 'Копій дод. (арк) — 2 sets × 6 sheets');
    }

    public function test_a_business_card_row_is_filled_from_a_snapshot_the_constructor_wrote(): void
    {
        $service = $this->service('Візитівки', 'constructor');

        $snapshot = app(ConstructorPricingService::class)->buildSnapshot(
            $service,
            $this->selectedOptions($service, [
                'Формат'                     => '90×50 мм',
                ServiceParameterGroup::PAPER => 'Крейдований 300 г/м² (глянець)',
                'Сторонність'                => 'Крейдований 300 г/м² (глянець): 4+4',
                'Ламінація'                  => '100 мкм (мат)',
            ]),
            quantity: 100,
        );

        $row = $this->mapOne('Візитівки', $snapshot, ['color_clicks' => 200]);

        $this->assertSame('90×50 мм', $row[4], 'Формат');
        $this->assertSame('Крейдований 300 г/м² (глянець)', $row[5], 'Папір');
        $this->assertSame('4+4', $row[6], 'Режим — the tail of the sidedness option');
        $this->assertSame('100 мкм (мат)', $row[7], 'Ламінація');
        $this->assertSame(200, $row[8], 'Кліки Колір');
    }

    public function test_a_lamination_row_is_filled_from_a_snapshot_the_constructor_wrote(): void
    {
        $service = $this->service('Ламінування', 'constructor');

        $snapshot = app(ConstructorPricingService::class)->buildSnapshot(
            $service,
            $this->selectedOptions($service, [
                'Формат'     => 'А4',
                'Тип плівки' => 'А4: 100 мкм (глянець)',
            ]),
            quantity: 20,
        );

        $row = $this->mapOne('Ламінування', $snapshot);

        $this->assertSame('А4', $row[4], 'Формат');
        $this->assertSame('100 мкм (глянець)', $row[5], 'Тип плівки, without the А4: prefix');
    }

    // ─── Fixtures for the boundary tests ────────────────────────────

    /** Map the single row of the sheet this snapshot belongs on. */
    private function mapOne(string $serviceName, array $snapshot, array $itemAttributes = []): array
    {
        $this->retroOrder($serviceName, $snapshot, $itemAttributes);

        $sheet = null;
        foreach ((new BackdatedOrdersExport)->sheets() as $candidate) {
            if ($candidate instanceof BackdatedOrdersCategorySheet) {
                $sheet = $candidate;
            }
        }

        $this->assertNotNull($sheet, 'The category sheet must exist to map anything');
        $row = $sheet->map($sheet->collection()->sole());

        $this->assertCount(count($sheet->headings()), $row, 'Row width must match the headings');

        return $row;
    }

    private function service(string $name, string $type): Service
    {
        return Service::factory()->create([
            'name'                => $name,
            'type'                => $type,
            'service_category_id' => ServiceCategory::factory()->create(['name' => $name])->id,
        ]);
    }

    private function paper(string $name, float $avgCost): InventoryItem
    {
        return InventoryItem::factory()->create([
            'name'                  => $name,
            'inventory_category_id' => InventoryCategory::factory()->create()->id,
            'avg_cost'              => $avgCost,
            'current_quantity'      => 1000,
            'is_active'             => true,
        ]);
    }

    private function clickCosts(): void
    {
        Material::factory()->create(['counter_type' => 'bw', 'click_cost' => 0.12, 'is_active' => true]);
        Material::factory()->create(['counter_type' => 'color', 'click_cost' => 0.60, 'is_active' => true]);
    }

    /**
     * Real parameter groups and options for a constructor service.
     *
     * @param  array<string, string>  $selection  group name => option name
     */
    private function selectedOptions(Service $service, array $selection): Collection
    {
        $options = collect();

        foreach ($selection as $groupName => $optionName) {
            $group = ServiceParameterGroup::factory()->create([
                'service_id' => $service->id,
                'name'       => $groupName,
            ]);

            $options->push(ServiceParameterOption::factory()->create([
                'group_id' => $group->id,
                'name'     => $optionName,
            ])->setRelation('group', $group));
        }

        return $options;
    }

    // ─── Summary sheet ──────────────────────────────────────────────

    /**
     * The item cost is set alongside the order total on purpose: the summary
     * decomposes an order into its items, because that is the only way a
     * category line can be true of a multi-service order. `orders.total_cost`
     * is the sum of its items' `total_price_cost` by construction —
     * OrderItemBuilder writes both from the same snapshot — so the two agreeing
     * here is the production state, not a convenience.
     */
    public function test_the_summary_totals_every_sheet_and_the_grand_total_row_adds_up(): void
    {
        $this->retroOrder('Сканування', itemAttributes: ['quantity' => 5, 'total_price_cost' => 12.50], orderAttributes: ['total_cost' => 12.50]);
        $this->retroOrder('Сканування', itemAttributes: ['quantity' => 3, 'total_price_cost' => 7.50], orderAttributes: ['total_cost' => 7.50]);
        $this->retroOrder('Чорно-білий друк', itemAttributes: ['quantity' => 2, 'total_price_cost' => 5.00], orderAttributes: ['total_cost' => 5.00]);

        $rows = $this->sheetsByTitle(new BackdatedOrdersExport)['Зведення']->collection();

        // The grand total is second-to-last now: a non-additive footnote about
        // the «Замовлень» column follows it.
        $grandTotal = $rows->first(fn ($row) => $row->first() === 'РАЗОМ')->all();
        $this->assertSame('РАЗОМ', $grandTotal[0]);
        $this->assertSame(3, $grandTotal[1], 'Three orders');
        $this->assertSame(10, $grandTotal[2], '5 + 3 + 2 units');
        $this->assertSame('25.00', $grandTotal[3], '12.50 + 7.50 + 5.00');
    }

    public function test_the_summary_counts_reconciled_orders_per_category(): void
    {
        $this->retroOrder('Сканування', orderAttributes: ['is_reconciled' => true]);
        $this->retroOrder('Сканування', orderAttributes: ['is_reconciled' => false]);

        $rows = $this->sheetsByTitle(new BackdatedOrdersExport)['Зведення']->collection();
        $scan = $rows->first()->all();

        $this->assertSame('Сканування', $scan[0]);
        $this->assertSame('1/2', $scan[7]);
    }

    // ─── The HTTP route ─────────────────────────────────────────────

    public function test_an_admin_can_download_the_workbook(): void
    {
        $this->retroOrder('Сканування');

        $response = $this->actingAs($this->admin)->get(route('admin.backdated-orders.export'));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheet',
            (string) $response->headers->get('content-type'),
            'The response must be an actual XLSX, which is what the phpspreadsheet upgrade risked',
        );
    }

    public function test_an_executor_cannot_download_the_workbook(): void
    {
        $executor = User::factory()->create(['role' => 'executor']);

        $this->actingAs($executor)
            ->get(route('admin.backdated-orders.export'))
            ->assertForbidden();
    }
}
