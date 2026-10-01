<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Models\UniversityRef;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ConstructorPricingService;
use App\Services\LimitService;
use App\Services\OrderNumberService;
use App\Services\RisoPricingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ImportRetroOrders — Batch import historical (retro) orders from JSON.
 *
 * Rows with same (date + signatory + department) are grouped into
 * ONE order with MULTIPLE OrderItems.
 *
 * JSON format (flat array of rows):
 * [
 *     {
 *         "date": "04.08.2025",
 *         "signatory": "Карприна",
 *         "department": "БШК",
 *         "format": "А4",
 *         "quantity": 1,
 *         "paper": "80",
 *         "color_mode": "4+0",
 *         "material_description": "optional note"
 *     },
 *     ...
 * ]
 */
class ImportRetroOrders extends Command
{
    protected $signature = 'retro:import
        {--file= : Path to JSON file with order data}
        {--dry-run : Validate only, do not create orders}
        {--user= : User ID to attribute orders to (default: first admin)}';

    protected $description = 'Import retro-orders from JSON data for historical record-keeping';

    /** @var array<string, string> Paper code → option label fragment */
    private const PAPER_MAP = [
        '80'   => 'Папір 80 г/м²',
        '160'  => 'Папір 160 г/м²',
        '200'  => 'Папір 200 г/м²',
        '150к' => 'Папір 150 г/м² (крейдований)',
        '250к' => 'Папір 250 г/м² (крейдований)',
        '300к' => 'Папір 300 г/м² (крейдований)',
        '160р' => 'Папір 160 г/м² (паст. рожевий)',
        '160ж' => 'Папір 160 г/м² (паст. жовтий)',
        '160з' => 'Папір 160 г/м² (паст. зелений)',
        '160б' => 'Папір 160 г/м² (паст. блакитний)',
    ];

    public function __construct(
        private readonly OrderNumberService $orderNumberService,
        private readonly ConstructorPricingService $pricingService,
        private readonly RisoPricingService $risoPricingService,
        private readonly LimitService $limitService,
        private readonly AuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $filePath = $this->option('file');

        // ─── Resolve user ────────────────────────────────
        $userId = $this->option('user')
            ? (int) $this->option('user')
            : User::where('role', 'admin')->value('id');

        if (! $userId) {
            $this->error('❌ No admin user found. Specify --user=ID.');

            return self::FAILURE;
        }

        // ─── Load data ───────────────────────────────────
        $rows = $filePath
            ? $this->loadFromFile($filePath)
            : $this->testData();

        if ($rows === null) {
            return self::FAILURE;
        }

        $this->info('📋 Found '.count($rows).' rows to import.');
        if ($isDryRun) {
            $this->warn('🔍 DRY RUN — no orders will be created.');
        }

        // ─── Pre-load services ───────────────────────────
        $colorService = Service::where('name', 'Кольоровий друк')->where('is_active', true)->first();
        $bwService = Service::where('name', 'Чорно-білий друк')->where('is_active', true)->first();
        $risoService = Service::where('name', 'ILIKE', '%тиражування%')->where('is_active', true)->first();

        if (! $colorService || ! $bwService) {
            $this->error('❌ Print services not found in database.');

            return self::FAILURE;
        }

        // ─── Group rows by consecutive (date + signatory + department) ─
        $groups = [];
        $prevKey = null;
        foreach ($rows as $row) {
            $key = $row['date'].'|'.$row['signatory'].'|'.$row['department'];
            if (isset($row['group'])) {
                $key .= '|'.$row['group'];
            }
            if ($key !== $prevKey) {
                $groups[] = [];
                $prevKey = $key;
            }
            $groups[array_key_last($groups)][] = $row;
        }

        $this->info('📦 Grouped into '.count($groups).' orders.');
        $this->newLine();

        // ─── Process groups ──────────────────────────────
        $created = 0;
        $errors = 0;
        $orderNum = 0;

        foreach ($groups as $key => $items) {
            $orderNum++;
            try {
                $this->processOrder($items, $orderNum, $colorService, $bwService, $risoService, $userId, $isDryRun);
                $created++;
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  ❌ Order #{$orderNum}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        if ($isDryRun) {
            $this->info("✅ Validation complete: {$created} valid orders, {$errors} errors.");
        } else {
            $this->info("✅ Import complete: {$created} orders created, {$errors} errors.");
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Process a group of rows as ONE order with MULTIPLE items.
     */
    private function processOrder(
        array $rows,
        int $orderNum,
        Service $colorService,
        Service $bwService,
        ?Service $risoService,
        int $userId,
        bool $isDryRun,
    ): void {
        $first = $rows[0];
        $orderDate = Carbon::createFromFormat('d.m.Y', $first['date'])->setTimezone('Europe/Kyiv');

        // ─── Resolve signatory ───────────────────────────
        $signatoryInput = trim($first['signatory']);
        $matched = UniversityRef::where('is_active', true)
            ->where('full_name', 'ILIKE', $signatoryInput.'%')
            ->first();

        $authorizedPerson = $matched ? $matched->full_name : $signatoryInput;
        if (! $matched) {
            $this->warn("     ⚠ Підписант '{$signatoryInput}' не знайдений у довіднику.");
        }

        // ─── Build items data ────────────────────────────
        $itemsData = [];
        $totalCost = 0;

        $this->line("  #{$orderNum} | {$first['date']} | {$authorizedPerson} | {$first['department']} | ".count($rows).' поз.');

        foreach ($rows as $idx => $row) {
            $rowType = $row['type'] ?? 'print';

            if ($rowType === 'riso') {
                // ─── Riso item ────────────────────────────
                if (! $risoService) {
                    throw new \RuntimeException('Riso service not found in database.');
                }
                $this->validateRisoRow($row);

                $format = strtoupper(str_replace(['а', 'А'], 'A', $row['format']));
                $pages = (int) $row['pages'];
                $sheets = (int) $row['sheets'];
                $copies = (int) $row['copies'];
                $sides = $sheets > 0 ? (int) round($pages / $sheets) : 1;
                // Total copies for pricing: each sheet × number of copies
                $totalCopies = $sheets * $copies;

                // `sheets` is how many distinct originals the paper run had, so it
                // is what the A4 halving rounds per.
                $snapshot = $this->risoPricingService->buildSnapshot(
                    $risoService, $totalCopies, $format, $sides, null, $sheets,
                );

                $costStr = number_format($snapshot['total_price_cost'], 2);
                $this->line("     [РІЗО {$format} {$sides}ст × {$sheets}арк × {$copies}коп = {$totalCopies} | {$costStr} грн]");

                $totalCost += $snapshot['total_price_cost'];

                $itemsData[] = [
                    'service'              => $risoService,
                    'snapshot'             => $snapshot,
                    'quantity'             => $totalCopies,
                    'material_description' => $row['material_description'] ?? null,
                ];

                continue;
            }

            // ─── Print item (BW / Color) ─────────────────
            $this->validateRow($row);

            $format = $row['format'];
            $paperCode = (string) $row['paper'];
            $colorMode = $row['color_mode'];
            $quantity = (int) $row['quantity'];
            $noPaper = ! empty($row['no_paper']);

            $isColor = in_array($colorMode, ['4+0', '4+4'], true);
            $service = $isColor ? $colorService : $bwService;

            $paperLabel = self::PAPER_MAP[$paperCode]
                ?? throw new \RuntimeException("Unknown paper code: {$paperCode}");

            // Find constructor options
            $formatOpt = $this->findOption($service, 'Формат', $format);
            $paperOptName = "{$format}: {$paperLabel}";
            $paperOpt = $this->findOption($service, ServiceParameterGroup::PAPER, $paperOptName);

            // BW sidedness: "А4: 1+0"; Color sidedness: "А4: Папір 80 г/м²: 4+0"
            $sidesOptName = $isColor
                ? "{$paperOptName}: {$colorMode}"
                : "{$format}: {$colorMode}";
            $sidesOpt = $this->findOption($service, 'Сторонність', $sidesOptName);

            // No paper = print only (envelopes, pre-printed stock, etc.)
            // Customer paper = paper selected but costs zeroed (customer provides their own)
            $customerPaper = ! empty($row['customer_paper']);
            $selectedOptions = $noPaper
                ? collect([$formatOpt, $sidesOpt])
                : collect([$formatOpt, $paperOpt, $sidesOpt]);
            $snapshot = $this->pricingService->buildSnapshot($service, $selectedOptions, $quantity, $customerPaper);

            $label = $noPaper ? 'без паперу' : $paperLabel;
            $costStr = number_format($snapshot['total_price_cost'], 2);
            $this->line("     [{$format} {$colorMode} × {$quantity} | {$label} | {$costStr} грн]");

            $totalCost += $snapshot['total_price_cost'];

            $itemsData[] = [
                'service'              => $service,
                'snapshot'             => $snapshot,
                'quantity'             => $quantity,
                'material_description' => $row['material_description'] ?? null,
            ];
        }

        $this->line('     💰 Разом: '.number_format($totalCost, 2).' грн');

        if ($isDryRun) {
            return;
        }

        // ─── Create order + items in transaction ─────────
        DB::transaction(function () use ($first, $orderDate, $userId, $authorizedPerson, $totalCost, $itemsData) {
            $numberData = $this->orderNumberService->generate(
                OrderType::Internal,
                (int) $orderDate->format('y'),
                (int) $orderDate->format('n'),
            );

            // The import date goes in before the insert, not in a second
            // `saveQuietly()` after it: `OrderObserver` dates the signatory ↔
            // cost-centre pair from `created_at` as it stands when `created`
            // fires. Eloquent leaves a dirty `created_at` alone, so one save
            // does both. The department reference is the same observer's job.
            $order = (new Order([
                ...$numberData,
                'type'                => OrderType::Internal->value,
                'status'              => OrderStatus::CompletedIssued->value,
                'user_id'             => $userId,
                'shift_id'            => null,
                'authorized_person'   => $authorizedPerson,
                'cost_center'         => $first['department'],
                'is_backdated'        => true,
                'request_received'    => true,
                'request_received_at' => $orderDate,
                'request_received_by' => $userId,
                'total_commercial'    => 0,
                'total_cost'          => $totalCost,
            ]))->forceFill(['created_at' => $orderDate]);

            $order->save();

            foreach ($itemsData as $item) {
                OrderItem::create([
                    'order_id'               => $order->id,
                    'service_id'             => $item['service']->id,
                    'service_snapshot'       => $item['snapshot'],
                    'service_name'           => $item['service']->name,
                    'material_description'   => $item['material_description'],
                    'quantity'               => $item['quantity'],
                    'unit_price_commercial'  => $item['snapshot']['unit_price_commercial'],
                    'unit_price_cost'        => $item['snapshot']['unit_price_cost'],
                    'total_price_commercial' => $item['snapshot']['total_price_commercial'],
                    'total_price_cost'       => $item['snapshot']['total_price_cost'],
                    'bw_clicks'              => $item['snapshot']['hardware_counters']['bw_clicks'] ?? 0,
                    'color_clicks'           => $item['snapshot']['hardware_counters']['color_clicks'] ?? 0,
                    'riso_clicks'            => $item['snapshot']['hardware_counters']['riso_clicks'] ?? 0,
                ]);
            }

            $this->reportCategoriesOutsideGroup($order, $userId);

            $this->info("     → Created: {$order->order_number}");
        });
    }

    /**
     * Ask the category question of the order just written, and record the answer.
     *
     * Owner's decision, 2026-08-01: of the three rules in
     * `LimitService::recordForOrder()`, the retro module asks this one and not
     * the two quotas — a quota describes a month whose budget has already been
     * spent and reset, while the categories describe the signatory, and a month
     * entered here is what somebody will read as the accounting record.
     *
     * The decision is about the module, so it holds here as well as in
     * `BackdatedOrderController` — otherwise it would apply only to the months
     * that happened to be typed in by hand. Nothing is refused: a row in the
     * file describes printing that already happened.
     */
    private function reportCategoriesOutsideGroup(Order $order, int $userId): void
    {
        $outside = $this->limitService->servicesOutsideSignatoryCategories(
            $order->authorized_person,
            $order->items()->get()->pluck('service_id'),
        );

        if ($outside === []) {
            return;
        }

        $names = implode(', ', $outside);
        $this->warn("     ⚠ Послуги поза дозволеними категоріями підписанта: {$names}");

        $user = User::find($userId);

        if (! $user) {
            return;
        }

        $this->auditService->log(
            eventType: 'category_not_allowed',
            user: $user,
            description: "Services outside the signatory's categories on imported order {$order->order_number}: {$names}",
            shiftId: null,
            meta: [
                'order_id'          => $order->id,
                'source'            => 'retro_import',
                'authorized_person' => $order->authorized_person,
                'services'          => $outside,
            ],
        );
    }

    private function validateRow(array $row): void
    {
        $required = ['date', 'signatory', 'department', 'format', 'quantity', 'paper', 'color_mode'];
        foreach ($required as $field) {
            if (empty($row[$field]) && $row[$field] !== 0) {
                throw new \RuntimeException("Missing required field: {$field}");
            }
        }
    }

    private function validateRisoRow(array $row): void
    {
        $required = ['date', 'signatory', 'department', 'format', 'pages', 'sheets', 'copies'];
        foreach ($required as $field) {
            if (empty($row[$field]) && $row[$field] !== 0) {
                throw new \RuntimeException("Missing required riso field: {$field}");
            }
        }
    }

    private function findOption(Service $service, string $groupName, string $optionName): ServiceParameterOption
    {
        return ServiceParameterOption::whereHas('group', fn ($q) => $q
            ->where('service_id', $service->id)
            ->where('name', $groupName))
            ->where('name', $optionName)
            ->where('is_active', true)
            ->first()
            ?? throw new \RuntimeException("Option '{$optionName}' not found in group '{$groupName}'");
    }

    private function loadFromFile(string $path): ?array
    {
        if (! file_exists($path)) {
            $this->error("❌ File not found: {$path}");

            return null;
        }

        $data = json_decode(file_get_contents($path), true);

        if (! is_array($data)) {
            $this->error('❌ Invalid JSON format. Expected array of objects.');

            return null;
        }

        return $data;
    }

    private function testData(): array
    {
        return [
            ['date' => '04.08.2025', 'signatory' => 'Карприна', 'department' => 'БШК', 'format' => 'А4', 'quantity' => 1, 'paper' => '80', 'color_mode' => '4+0'],
            ['date' => '04.08.2025', 'signatory' => 'Накченко', 'department' => 'Відділ роботи регіонального представництва', 'format' => 'А4', 'quantity' => 3, 'paper' => '200', 'color_mode' => '4+0'],
        ];
    }
}
