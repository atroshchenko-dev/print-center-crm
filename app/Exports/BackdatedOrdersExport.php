<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exports\Concerns\GroupsOrdersByService;
use App\Models\Order;
use App\Models\ServiceParameterGroup;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * BackdatedOrdersExport — XLSX export of backdated (retro) orders for accounting.
 *
 * Orders are grouped by service category into separate sheets,
 * each with category-specific detailed columns extracted from JSONB snapshots.
 */
class BackdatedOrdersExport implements WithMultipleSheets
{
    use GroupsOrdersByService;

    public function __construct(
        private readonly ?Carbon $from = null,
        private readonly ?Carbon $to = null,
        private readonly ?bool $reconciled = null,
    ) {}

    public function sheets(): array
    {
        $grouped = $this->groupOrdersByService($this->loadOrders());

        $sheets = [];
        foreach ($grouped as $categoryName => $categoryOrders) {
            $sheets[] = new BackdatedOrdersCategorySheet($categoryName, $categoryOrders);
        }
        $sheets[] = new BackdatedOrdersSummarySheet($grouped);

        return $sheets;
    }

    private function loadOrders(): Collection
    {
        return Order::with(['items', 'user', 'reconciler'])
            ->withEmailApprovalFlag()
            ->where('type', OrderType::Internal->value)
            ->where('is_backdated', true)
            ->whereNotIn('status', [OrderStatus::Cancelled->value])
            ->when($this->from && $this->to, fn ($q) => $q->whereBetween('created_at', [$this->from, $this->to]))
            ->when($this->reconciled !== null, fn ($q) => $q->where('is_reconciled', $this->reconciled))
            ->orderBy('created_at')
            ->get();
    }
}

// ═══════════════════════════════════════════════════════
//  Category Sheet — one per service type
// ═══════════════════════════════════════════════════════

class BackdatedOrdersCategorySheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    private string $type;

    /** Short name map for Excel tab titles (31-char limit) */
    private const TAB_NAMES = [
        'Чорно-білий друк'     => 'ЧБ',
        'Кольоровий друк'      => 'Колір',
        'Тиражування (RISO)'   => 'Тираж',
        'Ламінування'          => 'Ламінація',
        'Сканування'           => 'Скан',
        'Брошура (скоба)'      => 'Брошури',
        'Палітурка на пружині' => 'Переплет м\'як',
        'Палітурка тверда'     => 'Переплет тв',
        'Дипломи/Додатки'      => 'Дипломи',
        'Візитівки'            => 'Візитки',
    ];

    public function __construct(
        private readonly string $categoryName,
        private readonly Collection $orders,
    ) {
        $this->type = $this->detectType();
    }

    public function collection(): Collection
    {
        $rows = collect();
        foreach ($this->orders as $order) {
            foreach ($order->items as $item) {
                if ($item->service_name === $this->categoryName) {
                    $rows->push((object) ['order' => $order, 'item' => $item]);
                }
            }
        }

        return $rows;
    }

    // ─── Headings per type ───────────────────────────────

    public function headings(): array
    {
        $base = ['№ Замовлення', 'Дата', 'Підписант', 'Центр витрат'];
        $tail = ['Кількість', 'Ціна за од.', 'Собівартість, грн', 'Примітка', 'Заявка', 'Звірка'];

        return match ($this->type) {
            'print' => [
                ...$base, 'Формат', 'Папір', 'Режим',
                'Кліки Ч/Б', 'Кліки Колір',
                ...$tail,
            ],
            'riso' => [
                ...$base, 'Формат', 'Сторонність', 'Аркушів А3',
                'Тарифна група', 'Вартість кліку', 'Папір',
                'Кліки Різо',
                ...$tail,
            ],
            'brochure' => [
                ...$base, 'Формат',
                'Обкл. папір', 'Обкл. режим',
                'Блок папір', 'Блок аркушів', 'Блок режим(и)',
                'Кліки Ч/Б', 'Кліки Колір',
                ...$tail,
            ],
            'lam' => [
                ...$base, 'Формат', 'Тип плівки',
                ...$tail,
            ],
            'scan' => [
                ...$base, 'Формат',
                ...$tail,
            ],
            'bind' => [
                ...$base, 'Тип палітурки', 'Деталі',
                ...$tail,
            ],
            'diploma' => [
                ...$base,
                'Дипломів', 'Додатків (арк)', 'Академдовідок',
                'Копій дипл.', 'Копій дод. (арк)',
                'Кліки Ч/Б', 'Кліки Колір',
                ...$tail,
            ],
            'cards' => [
                ...$base, 'Формат', 'Папір', 'Режим', 'Ламінація',
                'Кліки Колір',
                ...$tail,
            ],
            default => [
                ...$base, 'Формат', 'Деталі',
                ...$tail,
            ],
        };
    }

    // ─── Map row per type ────────────────────────────────

    public function map($row): array
    {
        $order = $row->order;
        $item = $row->item;
        $ss = $item->service_snapshot ?? [];

        $base = [
            $order->order_number,
            $order->created_at->timezone('Europe/Kyiv')->format('d.m.Y'),
            $order->authorized_person ?? '—',
            $order->cost_center ?? '—',
        ];

        $tail = [
            $item->quantity,
            number_format((float) $item->unit_price_cost, 4, '.', ''),
            number_format((float) $item->total_price_cost, 2, '.', ''),
            $item->material_description ?? '',
            $this->requestLabel($order),
            $order->is_reconciled ? 'Звірено' : 'Очікує',
        ];

        return match ($this->type) {
            'print'    => [...$base, ...$this->mapPrint($item), ...$tail],
            'riso'     => [...$base, ...$this->mapRiso($item), ...$tail],
            'brochure' => [...$base, ...$this->mapBrochure($item), ...$tail],
            'lam'      => [...$base, ...$this->mapLam($item), ...$tail],
            'scan'     => [...$base, ...$this->mapScan($item), ...$tail],
            'bind'     => [...$base, ...$this->mapBind($item), ...$tail],
            'diploma'  => [...$base, ...$this->mapDiploma($item), ...$tail],
            'cards'    => [...$base, ...$this->mapCards($item), ...$tail],
            default    => [...$base, ...$this->mapGeneric($item), ...$tail],
        };
    }

    /**
     * Which confirmation the accountant will be looking for: a sheet in the
     * folder, or a click that only ever existed in the system.
     *
     * Reads the `has_email_approval` flag both loadOrders() methods add, not
     * the relation — a lookup per row would be one query per line of the
     * workbook.
     */
    private function requestLabel(Order $order): string
    {
        if (! $order->request_received) {
            return '—';
        }

        return $order->has_email_approval ? 'Отримано (лист)' : 'Отримано (папір)';
    }

    // ─── Print (BW / Color) ──────────────────────────────

    private function mapPrint($item): array
    {
        $format = '—';
        $paper = '—';
        $mode = '—';
        $ss = $item->service_snapshot ?? [];

        // Customer paper flag — snapshot-level marker
        if (! empty($ss['customer_paper'])) {
            $paper = 'Замовника';
        }

        foreach ($ss['constructor_snapshot'] ?? [] as $e) {
            $gn = $e['group_name'] ?? '';
            $on = $e['option_name'] ?? '';
            if ($gn === 'Формат') {
                $format = $on;
            } elseif ($gn === ServiceParameterGroup::PAPER && $paper !== 'Замовника') {
                $paper = preg_replace('/^А[34]:\s*/', '', $on);
            } elseif ($gn === 'Сторонність') {
                $parts = explode(': ', $on);
                $mode = end($parts);
            }
        }

        return [
            $format,
            $paper,
            $mode,
            $item->bw_clicks ?: '',
            $item->color_clicks ?: '',
        ];
    }

    // ─── Riso ────────────────────────────────────────────

    private function mapRiso($item): array
    {
        $rp = $item->service_snapshot['riso_params'] ?? [];

        return [
            $rp['format'] ?? '—',
            ($rp['sides'] ?? 1) == 2 ? '1+1' : '1+0',
            $rp['sheets_a3'] ?? '—',
            $rp['tier_label'] ?? '—',
            isset($rp['cost_per_copy']) ? number_format((float) $rp['cost_per_copy'], 4, '.', '') : '—',
            $rp['paper_name'] ?? '—',
            $item->riso_clicks ?: '',
        ];
    }

    // ─── Brochure ────────────────────────────────────────

    private function mapBrochure($item): array
    {
        $bp = $item->service_snapshot['brochure_params'] ?? [];
        $cover = $bp['cover'] ?? [];
        $block = $bp['block'] ?? [];

        // Collect block modes: "1+0 (5 арк), 4+0 (2 арк)"
        $blockModes = [];
        foreach ($block['entries'] ?? [] as $entry) {
            $blockModes[] = ($entry['mode'] ?? '?').' ('.($entry['sheets'] ?? 0).' арк)';
        }

        return [
            $bp['format'] ?? '—',
            $cover['paper_name'] ?? '—',
            $cover['print_mode'] ?? '—',
            $block['paper_name'] ?? '—',
            $block['total_sheets'] ?? '—',
            implode(', ', $blockModes) ?: '—',
            $item->bw_clicks ?: '',
            $item->color_clicks ?: '',
        ];
    }

    // ─── Scan ────────────────────────────────────────

    private function mapScan($item): array
    {
        $format = '—';
        foreach ($item->service_snapshot['constructor_snapshot'] ?? [] as $e) {
            if (($e['group_name'] ?? '') === 'Формат') {
                $format = $e['option_name'] ?? '—';
                break;
            }
        }

        return [$format];
    }

    // ─── Lamination ──────────────────────────────────────

    private function mapLam($item): array
    {
        $format = '—';
        $film = '—';

        foreach ($item->service_snapshot['constructor_snapshot'] ?? [] as $e) {
            $gn = $e['group_name'] ?? '';
            $on = $e['option_name'] ?? '';
            if ($gn === 'Формат') {
                $format = $on;
            } elseif (str_contains(mb_strtolower($gn), 'тип') || str_contains(mb_strtolower($gn), 'плівк')) {
                $film = preg_replace('/^А[34]:\s*/', '', $on);
            }
        }

        return [$format, $film];
    }

    // ─── Binding (Палітурка) ─────────────────────────────

    private function mapBind($item): array
    {
        $type = '—';
        $detail = '—';

        // The category name itself tells the type
        $type = str_contains($this->categoryName, 'пружин') ? 'На пружині' : 'Тверда';

        foreach ($item->service_snapshot['constructor_snapshot'] ?? [] as $e) {
            $gn = $e['group_name'] ?? '';
            $on = $e['option_name'] ?? '';
            if ($gn !== 'Формат' && ! empty($on)) {
                $detail = $on;
            }
        }

        return [$type, $detail];
    }

    // ─── Diploma ─────────────────────────────────────────

    private function mapDiploma($item): array
    {
        $dp = $item->service_snapshot['diploma_params'] ?? [];
        $copies = $dp['copies'] ?? [];

        // Count supplement sheets
        $suppSheets = 0;
        foreach ($dp['supplements'] ?? [] as $supp) {
            $suppSheets += (int) ($supp['sheets'] ?? 0);
        }

        return [
            $dp['diplomas']['qty'] ?? 0,
            $suppSheets ?: '',
            $dp['academic_records']['qty'] ?? 0,
            $copies['diploma_copies']['qty'] ?? '',
            $copies['supplement_copies']['sheets'] ?? '',
            $item->bw_clicks ?: '',
            $item->color_clicks ?: '',
        ];
    }

    // ─── Generic fallback ────────────────────────────────

    private function mapGeneric($item): array
    {
        $format = '—';
        $detail = '—';

        foreach ($item->service_snapshot['constructor_snapshot'] ?? [] as $e) {
            $gn = $e['group_name'] ?? '';
            $on = $e['option_name'] ?? '';
            if ($gn === 'Формат') {
                $format = $on;
            } elseif (! empty($on)) {
                $detail = $on;
            }
        }

        return [$format, $detail];
    }

    // ─── Business Cards (Візитівки) ──────────────────────

    private function mapCards($item): array
    {
        $format = '—';
        $paper = '—';
        $mode = '—';
        $lamination = '—';
        $ss = $item->service_snapshot ?? [];

        if (! empty($ss['customer_paper'])) {
            $paper = 'Замовника';
        }

        foreach ($ss['constructor_snapshot'] ?? [] as $e) {
            $gn = $e['group_name'] ?? '';
            $on = $e['option_name'] ?? '';
            if ($gn === 'Формат') {
                $format = $on;
            } elseif ($gn === ServiceParameterGroup::PAPER && $paper !== 'Замовника') {
                $paper = $on;
            } elseif ($gn === 'Сторонність') {
                $parts = explode(': ', $on);
                $mode = end($parts);
            } elseif ($gn === 'Ламінація') {
                $lamination = $on;
            }
        }

        return [
            $format,
            $paper,
            $mode,
            $lamination,
            $item->color_clicks ?: '',
        ];
    }

    // ─── Type detection ──────────────────────────────────

    private function detectType(): string
    {
        $name = mb_strtolower($this->categoryName);

        if (str_contains($name, 'друк') || str_contains($name, 'колір') || str_contains($name, 'чорно')) {
            return 'print';
        }
        if (str_contains($name, 'тираж') || str_contains($name, 'riso') || str_contains($name, 'різо')) {
            return 'riso';
        }
        if (str_contains($name, 'скан')) {
            return 'scan';
        }
        if (str_contains($name, 'ламін')) {
            return 'lam';
        }
        if (str_contains($name, 'паліт')) {
            return 'bind';
        }
        if (str_contains($name, 'брошур')) {
            return 'brochure';
        }
        if (str_contains($name, 'диплом')) {
            return 'diploma';
        }
        if (str_contains($name, 'візит')) {
            return 'cards';
        }

        return 'generic';
    }

    /**
     * PhpSpreadsheet throws on `* : / \ ? [ ]` in a sheet title, and TAB_NAMES
     * only covers the seeded services — anything an admin adds falls through to
     * the raw name. A service called "Друк А4/А3" would have taken the whole
     * workbook down with a 500 on download, and this is not hypothetical: the
     * seeded "Дипломи/Додатки" already carries a slash and survives only
     * because the map rewrites it. Duplicates need no handling — PhpSpreadsheet
     * appends " 1", " 2" itself.
     */
    public function title(): string
    {
        if (isset(self::TAB_NAMES[$this->categoryName])) {
            return self::TAB_NAMES[$this->categoryName];
        }

        $safe = str_replace(['*', ':', '/', '\\', '?', '[', ']'], '-', $this->categoryName);

        return mb_substr($safe, 0, 31);
    }
}

// ═══════════════════════════════════════════════════════
//  Summary Sheet — totals per category
// ═══════════════════════════════════════════════════════

class BackdatedOrdersSummarySheet implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(
        private readonly Collection $grouped,
    ) {}

    /**
     * Totals per category, counted over the items that category actually holds.
     *
     * They used to be counted over whole orders: a category line summed
     * `orders.total_cost`, so a two-service order put all of its money on the
     * line of whichever service came first, while the detail sheet below it
     * showed only that service's rows. The line and the rows under it did not
     * add up. An order now appears on every line it has work on, and each line
     * counts only its own items — so the lines sum to the grand total, and the
     * grand total still counts each order once.
     */
    public function collection(): Collection
    {
        $rows = collect();

        foreach ($this->grouped as $categoryName => $orders) {
            $items = $this->itemsOf($orders, (string) $categoryName);
            $reconciled = $orders->where('is_reconciled', true)->count();

            $rows->push(collect([
                $categoryName,
                $orders->count(),
                (int) $items->sum('quantity'),
                number_format((float) $items->sum(fn ($i) => (float) $i->total_price_cost), 2, '.', ''),
                (int) $items->sum('bw_clicks') ?: '',
                (int) $items->sum('color_clicks') ?: '',
                (int) $items->sum('riso_clicks') ?: '',
                "{$reconciled}/{$orders->count()}",
            ]));
        }

        // Grand total. An order that spans two categories is on two lines above
        // and must still be one order here — hence unique() on the id.
        $allOrders = $this->grouped->flatten()->unique('id');
        $allItems = $allOrders->flatMap(fn ($o) => $o->items);

        $rows->push(collect([
            'РАЗОМ',
            $allOrders->count(),
            (int) $allItems->sum('quantity'),
            number_format((float) $allItems->sum(fn ($i) => (float) $i->total_price_cost), 2, '.', ''),
            (int) $allItems->sum('bw_clicks') ?: '',
            (int) $allItems->sum('color_clicks') ?: '',
            (int) $allItems->sum('riso_clicks') ?: '',
            $allOrders->where('is_reconciled', true)->count().'/'.$allOrders->count(),
        ]));

        // «Замовлень» is not additive: an order spanning two categories is on
        // two lines above, each counting it, so the column sums past РАЗОМ.
        // Worded as rows, not categories: `groupOrdersByService()` keys on
        // `service_name`, so two services sharing one ServiceCategory are two
        // rows here too, each counting the same order again.
        $rows->push(collect([
            '* Замовлення рахується в кожному рядку, де має позиції, тому сума за рядками більша за РАЗОМ.',
        ]));

        return $rows;
    }

    /**
     * The items of these orders that belong to this category, and no others.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function itemsOf(Collection $orders, string $categoryName): Collection
    {
        return $orders->flatMap(fn ($o) => $o->items)
            ->filter(fn ($item) => $item->service_name === $categoryName)
            ->values();
    }

    public function headings(): array
    {
        return [
            'Категорія',
            'Замовлень*',
            'Кількість',
            'Собівартість, грн',
            'Кліки Ч/Б',
            'Кліки Колір',
            'Кліки Різо',
            'Звірено',
        ];
    }

    public function title(): string
    {
        return 'Зведення';
    }
}
