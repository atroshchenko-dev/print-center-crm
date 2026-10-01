<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RisoPriceTier;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SignatoryGroup;
use App\Models\UniversityRef;
use App\Services\LimitService;
use App\Support\KyivClock;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Re-ask today's rules of yesterday's orders.
 *
 * Thirty-three audit rounds changed how price is formed and how three rules are
 * enforced. **Nothing was ever repriced**, by
 * migration or by command, and that stays true here.
 *
 * **It writes nothing to the database** — not an order, not a movement, not an
 * audit-log line — and a test asserts exactly that by comparing the tables and
 * the orders' own `updated_at` across a run. The single thing it can write is
 * the CSV you ask for with `--csv`, to a path you name. Said precisely because
 * «writes nothing» would be the kind of almost-true claim this audit spent
 * thirty-three rounds taking apart.
 *
 * **Retro orders are out** (`is_backdated`), as they are out of every other
 * limit and ledger path: they record a period whose quota was spent and reset
 * long ago, and their prices were entered from a paper report rather than
 * computed.
 *
 * ── The one distinction this command exists to keep ──────────────────────
 *
 * Every check is labelled **DECISIVE** or **EXPOSURE**, and they mean different
 * things:
 *
 *   - **DECISIVE** — the stored data carries a fingerprint that can only have
 *     been left by the old code. A hit is a fact about that order.
 *   - **EXPOSURE** — the old code could have priced this order differently, and
 *     the data needed to decide **was never recorded**. A hit is a question,
 *     and the command says how large the question is rather than pretending to
 *     answer it.
 *
 * Conflating those two is how a review turns into a rumour. A count of
 * "suspect orders" that mixes proven mispricing with unrecorded inputs is worse
 * than no count, because somebody will quote it.
 *
 * ── What is deliberately **not** checked, and why ─────────────────────────
 *
 *   - **R32-1 / R32-2** (constructors quoting a wrong commercial price) — the
 *     order was never computed wrongly. `OrderItemBuilder` trusts a snapshot
 *     only on a byte-for-byte match, so the cash register always held the
 *     server's figure. What diverged was the number read aloud before saving,
 *     and no record of that exists anywhere;
 *   - **R20-2 / §1.8-біс** (fallback click cost 0.12 / 0.60) — the fallback
 *     fires only with no active material of that type, and production has been
 *     measured four times with exactly one active material per type. There is
 *     nothing to look for;
 *   - **R33-2** (cost-centre quota) — `department_limits` has never held a row,
 *     so no order was ever judged by it.
 *
 * Those three are stated here rather than silently omitted: a reviewer that
 * does not say what it skipped reads as a reviewer that found nothing there.
 */
class AuditOrdersCommand extends Command
{
    protected $signature = 'audit:orders
                            {--check=* : Run only these checks (see --list)}
                            {--since= : Only orders created on or after this Kyiv date (Y-m-d)}
                            {--until= : Only orders created on or before this Kyiv date (Y-m-d)}
                            {--csv= : Write every finding to this file}
                            {--list : List the checks and exit}';

    protected $description = 'Re-ask today\'s pricing and limit rules of past orders (read-only, retro excluded)';

    /** @var array<string, array{title: string, kind: string, finding: string, method: string}> */
    private const CHECKS = [
        'riso-tier' => [
            'title'   => 'Тариф Різо не збігається з кількістю аркушів (R30-1)',
            'kind'    => 'DECISIVE',
            'finding' => 'R30-1',
            'method'  => 'checkRisoTier',
        ],
        'order-number' => [
            'title'   => 'Префікс номера не збігається з типом замовлення (R18-1)',
            'kind'    => 'DECISIVE',
            'finding' => 'R18-1',
            'method'  => 'checkOrderNumber',
        ],
        'reconciled-before-edit' => [
            'title'   => 'Замовлення відредаговано після звірки, і звірка лишилась (R17-2)',
            'kind'    => 'DECISIVE',
            'finding' => 'R17-2',
            'method'  => 'checkReconciledBeforeEdit',
        ],
        'orphan-reference' => [
            'title'   => 'Підрозділ або підписант замовлення не існує в довіднику (R18-2, R19-1, R20-1)',
            'kind'    => 'DECISIVE',
            'finding' => 'R18-2',
            'method'  => 'checkOrphanReference',
        ],
        'internal-only-service' => [
            'title'   => 'Послуга «лише для внутрішніх» у комерційному замовленні (M-9)',
            'kind'    => 'DECISIVE',
            'finding' => 'M-9',
            'method'  => 'checkInternalOnlyService',
        ],
        'diploma-free-sheet' => [
            'title'   => 'Диплом видано, а аркуші не списані зі складу (R19-2)',
            'kind'    => 'DECISIVE',
            'finding' => 'R19-2',
            'method'  => 'checkDiplomaFreeSheet',
        ],
        'return-price' => [
            'title'   => 'Повернення складу оцінене не тим рухом (R6-9)',
            'kind'    => 'DECISIVE',
            'finding' => 'R6-9',
            'method'  => 'checkReturnPrice',
        ],
        'group-pool' => [
            'title'   => 'Місячний пул групи перевищено, а прапорець не стоїть (R17-1)',
            'kind'    => 'DECISIVE',
            'finding' => 'R17-1',
            'method'  => 'checkGroupPool',
        ],
        'riso-originals' => [
            'title'   => 'Кількість оригіналів Різо не записана — ціну не можна перевірити (R14-1)',
            'kind'    => 'EXPOSURE',
            'finding' => 'R14-1',
            'method'  => 'checkRisoOriginals',
        ],
    ];

    public function __construct(
        private readonly LimitService $limitService,
    ) {
        parent::__construct();
    }

    /** @var array<int, array<string, string>> */
    private array $findings = [];

    public function handle(): int
    {
        if ($this->option('list')) {
            $this->listChecks();

            return self::SUCCESS;
        }

        $selected = $this->selectedChecks();

        if ($selected === []) {
            $this->error('Жодна назва перевірки не збіглася. `--list` показує наявні.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('audit:orders — перевірка минулих замовлень сьогоднішніми правилами');
        $this->line('Записів не робить. Ретро-замовлення (`is_backdated`) виключені.');
        $this->window();
        $this->line('');

        $summary = [];

        foreach ($selected as $key) {
            $check = self::CHECKS[$key];
            /** @var array<int, array{order: string, detail: string}> $hits */
            $hits = $this->{$check['method']}();

            $summary[] = [
                $key,
                $check['kind'],
                $check['finding'],
                (string) count($hits),
            ];

            $this->renderCheck($check, $hits);

            foreach ($hits as $hit) {
                $this->findings[] = [
                    'check'   => $key,
                    'kind'    => $check['kind'],
                    'finding' => $check['finding'],
                    'order'   => $hit['order'],
                    'detail'  => $hit['detail'],
                ];
            }
        }

        $this->line('');
        $this->table(['перевірка', 'рід', 'знахідка', 'влучань'], $summary);

        $decisive = count(array_filter($this->findings, fn ($f) => $f['kind'] === 'DECISIVE'));
        $exposure = count($this->findings) - $decisive;

        $this->line('');
        $this->line("Доведених розходжень: <options=bold>{$decisive}</>");
        $this->line("Питань без відповіді в даних: <options=bold>{$exposure}</>");
        $this->line('');
        $this->line('Доведене — це факт про замовлення. Питання — це те, чого в базі');
        $this->line('немає; складати їх в одне число не можна.');

        if ($path = $this->option('csv')) {
            $this->writeCsv((string) $path);
        }

        // Read-only: findings are never a failure of the command itself.
        return self::SUCCESS;
    }

    // ─── Checks ──────────────────────────────────────────

    /**
     * DECISIVE — the tier the snapshot names does not contain the sheet count
     * the same snapshot names.
     *
     * `RisoPriceTier::findForQuantity()` had one fallback covering two cases,
     * and the second was a **gap inside** the ladder, where it returned the
     * lowest-numbered tier — the dearest one. `tier_label` is the only place
     * that gave it away: it said «50–99» over a run of 220 sheets.
     *
     * Needs no historical price list: the snapshot argues with itself.
     *
     * @return array<int, array{order: string, detail: string}>
     */
    private function checkRisoTier(): array
    {
        $hits = [];

        foreach ($this->items('riso') as $item) {
            $params = $item->service_snapshot['riso_params'] ?? [];
            $sheets = (int) ($params['sheets_a3'] ?? 0);
            $label = (string) ($params['tier_label'] ?? '');

            if ($sheets <= 0 || $label === '') {
                continue;
            }

            [$min, $max] = $this->parseTierLabel($label);

            if ($min === null) {
                continue;
            }

            if ($sheets < $min || ($max !== null && $sheets > $max)) {
                $cost = (float) ($params['cost_per_copy'] ?? 0);
                $hits[] = [
                    'order'  => $this->numberOf($item),
                    'detail' => "аркушів {$sheets}, а тариф названо «{$label}» "
                              ."(ціна за копію {$cost}); позиція «{$item->service_name}»",
                ];
            }
        }

        return $hits;
    }

    /**
     * DECISIVE — the number's prefix and the order's type disagree.
     *
     * Editing an order's type used to leave the old number in place, and that
     * number is printed on the paper request, the commercial export and the
     * ledger line.
     *
     * @return array<int, array{order: string, detail: string}>
     */
    private function checkOrderNumber(): array
    {
        $hits = [];

        foreach ($this->orders()->get() as $order) {
            $expected = $order->type === OrderType::Commercial ? 'COM-' : 'INT-';

            if (! str_starts_with($order->order_number, $expected)) {
                $hits[] = [
                    'order'  => $order->order_number,
                    'detail' => "тип «{$order->type->value}» очікує префікс {$expected}",
                ];
            }
        }

        return $hits;
    }

    /**
     * DECISIVE — the order was reconciled, then edited, and the reconciliation
     * stayed.
     *
     * Since R17-2 an edit clears it. Anything still carrying an older
     * `reconciled_at` than `updated_at` was reconciled against contents that no
     * longer exist.
     *
     * @return array<int, array{order: string, detail: string}>
     */
    private function checkReconciledBeforeEdit(): array
    {
        // The edits, from the journal — not from `updated_at`.
        //
        // Its first version compared `reconciled_at` with `updated_at`, and on
        // production that returned six orders, one of them reconciled and
        // «changed» in the same minute. Of course it was: reconciling *is* an
        // update — `['is_reconciled' => true, 'reconciled_at' => now()]` writes
        // `updated_at` in the same statement — and so is every status change
        // afterwards. R17-2 is about the contents changing, and `updated_at`
        // cannot tell that from a hand-over.
        //
        // A check labelled DECISIVE that returns candidates is the defect this
        // command was written to find, one level up. `order_edited` has been in
        // the journal since round 2 and means exactly one thing.
        //
        // The two instants come from different clocks — `reconciled_at` from
        // the application, `audit_logs.created_at` from the database, which
        // stamps it with `useCurrent()` — so a tie inside the same second is
        // not evidence of an order. It never matters in practice: an edit is a
        // separate visit, minutes or days later. Worth knowing before anyone
        // reads a one-second gap as a finding.
        $editedAt = [];

        foreach (AuditLog::where('event_type', 'order_edited')->get() as $entry) {
            $orderId = (int) ($entry->meta['order_id'] ?? 0);

            if ($orderId > 0) {
                $editedAt[$orderId][] = $entry->created_at;
            }
        }

        $hits = [];

        $orders = $this->orders()
            ->where('is_reconciled', true)
            ->whereNotNull('reconciled_at')
            ->get();

        foreach ($orders as $order) {
            $after = collect($editedAt[$order->id] ?? [])
                ->filter(fn ($at) => $at->gt($order->reconciled_at));

            if ($after->isEmpty()) {
                continue;
            }

            $hits[] = [
                'order'  => $order->order_number,
                'detail' => 'звірено '.$this->kyiv($order->reconciled_at)
                          .', відредаговано '.$this->kyiv($after->max())
                          .' — і звірка лишилась',
            ];
        }

        return $hits;
    }

    /**
     * DECISIVE — the order names a cost centre or a signatory that no reference
     * row carries, deleted rows included.
     *
     * `orders.cost_center` and `orders.authorized_person` are strings, and every
     * quota rule looks the reference up by them. A rename used to detach the
     * order from its quota silently, a delete used to hand the quota
     * away, and two rows under one name made the lookup a coin toss
     *. Matched the way the unique index matches: `LOWER(TRIM(…))`.
     *
     * @return array<int, array{order: string, detail: string}>
     */
    private function checkOrphanReference(): array
    {
        $departments = Department::withTrashed()->pluck('name')
            ->map(fn ($n) => $this->fold($n))->flip();
        $signatories = UniversityRef::withTrashed()->pluck('full_name')
            ->map(fn ($n) => $this->fold($n))->flip();

        $hits = [];

        foreach ($this->orders()->get() as $order) {
            $missing = [];

            if (! empty($order->cost_center) && ! $departments->has($this->fold($order->cost_center))) {
                $missing[] = "центр витрат «{$order->cost_center}»";
            }

            if (! empty($order->authorized_person) && ! $signatories->has($this->fold($order->authorized_person))) {
                $missing[] = "підписант «{$order->authorized_person}»";
            }

            if ($missing !== []) {
                $hits[] = [
                    'order'  => $order->order_number,
                    'detail' => 'немає в довіднику: '.implode(', ', $missing),
                ];
            }
        }

        return $hits;
    }

    /**
     * DECISIVE — a commercial order carries a service from a category that is
     * not available commercially.
     *
     * `scopeAvailableFor()` existed and was called from nowhere, so such a
     * service went into a commercial order at 0 ₴.
     *
     * @return array<int, array{order: string, detail: string}>
     */
    private function checkInternalOnlyService(): array
    {
        $hits = [];

        // Two lookup maps rather than walking `service.category` per item: one
        // query each instead of the relation chain, and no traversal for static
        // analysis to argue with.
        $categoryOfService = Service::withTrashed()->pluck('service_category_id', 'id');
        $availabilityOfCategory = ServiceCategory::pluck('available_for', 'id');

        $items = OrderItem::query()
            ->with('order')
            ->whereHas('order', fn ($q) => $this->constrain($q)->where('type', OrderType::Commercial->value))
            ->get();

        foreach ($items as $item) {
            $categoryId = $item->service_id ? ($categoryOfService[$item->service_id] ?? null) : null;
            $availableFor = $categoryId ? ($availabilityOfCategory[$categoryId] ?? null) : null;

            if ($availableFor === null) {
                continue;
            }

            $available = is_array($availableFor) ? $availableFor : (array) json_decode((string) $availableFor, true);

            if (! in_array('commercial', $available, true)) {
                $hits[] = [
                    'order'  => $this->numberOf($item),
                    'detail' => "послуга «{$item->service_name}» доступна лише для: "
                              .implode(', ', $available)
                              .'; сума позиції '.$item->total_price_commercial.' ₴',
                ];
            }
        }

        return $hits;
    }

    /**
     * DECISIVE — a diploma was handed over and its sheets never left the shelf.
     *
     * `resolveAvgCost(null)` was `0.0` and `addDeduction(null, …)` returned at
     * once, so a deactivated or renamed store item made the sheet free **and**
     * invisible to stock, without a word. The missing movement is the
     * tell: the snapshot promises a deduction, the ledger has none.
     *
     * @return array<int, array{order: string, detail: string}>
     */
    private function checkDiplomaFreeSheet(): array
    {
        $issued = [OrderStatus::PaidIssued->value, OrderStatus::CompletedIssued->value];
        $hits = [];

        foreach ($this->items('diploma') as $item) {
            if (! in_array($this->orderOf($item)->status->value, $issued, true)) {
                continue;
            }

            $declared = $item->service_snapshot['diploma_params'] ?? [];
            $sheets = $this->diplomaSheets($declared);

            if ($sheets <= 0) {
                continue;
            }

            $deducted = collect($item->service_snapshot['inventory_deductions'] ?? [])
                ->sum(fn ($d) => (float) ($d['qty'] ?? 0)) * (int) $item->quantity;

            $moved = (float) InventoryMovement::where('reference_type', Order::class)
                ->where('reference_id', $item->order_id)
                ->where('type', 'auto_deduct')
                ->sum(DB::raw('ABS(quantity)'));

            if ($deducted <= 0.0001) {
                $hits[] = [
                    'order'  => $this->numberOf($item),
                    'detail' => "диплом на {$sheets} аркуш(ів), у знімку списання немає жодного "
                              ."(рухів по замовленню: {$moved})",
                ];
            }
        }

        return $hits;
    }

    /**
     * DECISIVE — a return was valued at a price no deduction of that item on
     * that order ever carried.
     *
     * One order can deduct the same item twice at different AVCO prices — two
     * items on the same paper — and `returnStock()` used to look the price up
     * again with `->first()` instead of taking it from the movement being
     * reversed. Two returns of 40 ₴ where it should have been 40 and 10.
     *
     * @return array<int, array{order: string, detail: string}>
     */
    private function checkReturnPrice(): array
    {
        $orderIds = $this->orders()->withTrashed()->pluck('id');

        $movements = InventoryMovement::whereIn('reference_id', $orderIds)
            ->where('reference_type', Order::class)
            ->whereIn('type', ['auto_deduct', 'return'])
            ->get()
            ->groupBy(fn ($m) => $m->reference_id.'|'.$m->inventory_item_id);

        $numbers = $this->orders()->withTrashed()->pluck('order_number', 'id');
        $hits = [];

        foreach ($movements as $key => $group) {
            [$orderId] = explode('|', (string) $key);

            $deductPrices = $group->where('type', 'auto_deduct')
                ->pluck('unit_cost')->map(fn ($c) => (float) $c)->unique();
            $returns = $group->where('type', 'return');

            if ($returns->isEmpty() || $deductPrices->count() < 2) {
                continue;
            }

            $returnPrices = $returns->pluck('unit_cost')->map(fn ($c) => (float) $c)->unique();

            if ($returnPrices->count() === 1) {
                $hits[] = [
                    'order'  => (string) ($numbers[(int) $orderId] ?? "order#{$orderId}"),
                    'detail' => 'списано за цінами ['.$deductPrices->implode(', ')
                              .'], а всі повернення оцінені як '.$returnPrices->first(),
                ];
            }
        }

        return $hits;
    }

    /**
     * DECISIVE — the signatory group's monthly pool was over, and the order
     * does not say so.
     *
     * Until R17-1 the edit path checked no limit at all and took
     * `limit_exceeded` from the browser. The pool is summed from the orders
     * themselves, so it can be re-asked of any past month — through
     * `LimitService`, not a second copy of the query.
     *
     * @return array<int, array{order: string, detail: string}>
     */
    private function checkGroupPool(): array
    {
        $months = $this->orders()
            ->where('type', OrderType::Internal->value)
            ->get(['id', 'order_number', 'created_at', 'limit_exceeded', 'authorized_person'])
            ->groupBy(fn ($o) => KyivClock::format($o->created_at, 'Y-m'));

        $hits = [];

        foreach ($months as $month => $orders) {
            // The month bounds come from KyivClock, not from a string this
            // command builds: a UTC month's first three hours belong to the
            // previous month in Kyiv, and that is R3-7 and R6-1.
            $anyInMonth = $orders->first()->created_at;
            [$from, $to] = KyivClock::monthWindow($anyInMonth);

            foreach (SignatoryGroup::withTrashed()->get() as $group) {
                $limit = $group->monthlyLimit($anyInMonth);

                if ($limit === null) {
                    continue;
                }

                $usage = $this->limitService->groupUsageInWindow($group->id, $from, $to);

                if ($usage <= $limit) {
                    continue;
                }

                $names = UniversityRef::withTrashed()
                    ->where('signatory_group_id', $group->id)
                    ->pluck('full_name')
                    ->map(fn ($n) => $this->fold($n))
                    ->flip();

                foreach ($orders as $order) {
                    if (empty($order->authorized_person) || ! $names->has($this->fold($order->authorized_person))) {
                        continue;
                    }

                    if (! $order->limit_exceeded) {
                        $hits[] = [
                            'order'  => $order->order_number,
                            'detail' => "{$month}: група «{$group->name}» витратила {$usage} з {$limit} копій, "
                                      .'а прапорець перевищення не стоїть',
                        ];
                    }
                }
            }
        }

        return $hits;
    }

    /**
     * EXPOSURE — an A4 Riso item priced before the number of originals was
     * recorded at all.
     *
     * A risograph burns one master per original, so sheets are rounded **per
     * original**; the server used to round the whole run, and the field that
     * would settle it (`riso_params.originals`) did not exist in any request,
     * FormRequest or builder before the fix. The sheet count decides the
     * **tier**, and the tier multiplies the whole run.
     *
     * So this cannot be decided — and the command does not pretend to. It prints
     * the size of the question: what the same job would have cost had it been
     * made of k originals, for every k that divides the run, and whether the
     * tier itself would have moved.
     *
     * A3 runs are not listed: there is no halving, so originals cannot change
     * the sheet count.
     *
     * @return array<int, array{order: string, detail: string}>
     */
    private function checkRisoOriginals(): array
    {
        $hits = [];

        foreach ($this->items('riso') as $item) {
            $params = $item->service_snapshot['riso_params'] ?? [];

            if (array_key_exists('originals', $params)) {
                continue;
            }

            if (strtoupper((string) ($params['format'] ?? 'A3')) !== 'A4') {
                continue;
            }

            $copies = (int) $item->quantity;
            $stored = (int) ($params['sheets_a3'] ?? 0);

            if ($copies < 2 || $stored <= 0) {
                continue;
            }

            $sides = max(1, (int) ($params['sides'] ?? 1));
            $paperCost = (float) ($params['paper_cost_a3'] ?? 0);
            $storedRate = (float) ($params['cost_per_copy'] ?? 0);
            $storedTotal = round($stored * $storedRate * $sides + $stored * $paperCost, 2);

            // Every divisor that actually moves the money, with no cap.
            //
            // Two bounds get printed, and the reason is what the first run of
            // this check on production showed: for **every** item the largest
            // difference fell at `k = copies` — one copy per original — and the
            // page filled with figures like «+233 ₴». Arithmetically true and
            // operationally absurd: a risograph burns a master per original, so
            // 500 originals of one copy each is not a job anybody runs.
            //
            // Reporting only that ceiling is the mirror of the silent cap it
            // replaced. Both ends are printed instead, because the difference
            // between them is the answer:
            //
            //   - the **smallest** k that changes anything — the realistic
            //     floor, and usually a couple of hryvnia;
            //   - the arithmetic maximum, named as implausible.
            //
            // The rule underneath is worth knowing: the halving only bites when
            // `copies / k` is odd, so most plausible splits change nothing at
            // all.
            $moved = [];

            foreach ($this->divisors($copies) as $k) {
                $sheets = $k * (int) ceil(($copies / $k) / 2);
                $tier = RisoPriceTier::findForQuantity($sheets);

                // A gap in today's ladder cannot price that run at all, and
                // inventing a number here would be R30-1 rebuilt by hand.
                if ($tier === null) {
                    continue;
                }

                $total = round($sheets * (float) $tier->cost_per_copy * $sides + $sheets * $paperCost, 2);
                $delta = $total - $storedTotal;

                if (abs($delta) < 0.005) {
                    continue;
                }

                $moved[] = [
                    'k'      => $k,
                    'sheets' => $sheets,
                    'total'  => $total,
                    'delta'  => $delta,
                    'tier'   => $tier->max_qty ? "{$tier->min_qty}–{$tier->max_qty}" : "{$tier->min_qty}+",
                ];
            }

            // No split changes the money: nothing is at stake, and listing it
            // would inflate the question.
            if ($moved === []) {
                continue;
            }

            $floor = $moved[0];                                   // divisors ascend, so this is the smallest k
            $ceiling = collect($moved)->sortByDesc(fn ($m) => abs($m['delta']))->first();

            $detail = "{$copies} копій А4: у знімку {$stored} арк. на "
                    .number_format($storedTotal, 2).' ₴. Найменша можлива різниця — за '
                    ."{$floor['k']} оригіналів: {$floor['sheets']} арк., "
                    .$this->signed($floor['delta']).' ₴'
                    .($floor['tier'] === (string) ($params['tier_label'] ?? '') ? '' : " (тариф «{$floor['tier']}»)");

            if ($ceiling['k'] !== $floor['k']) {
                $detail .= '. Арифметична стеля — за '.$ceiling['k'].' оригіналів (по '
                         .intdiv($copies, $ceiling['k']).' копії): '
                         .$this->signed($ceiling['delta']).' ₴'
                         .($ceiling['k'] === $copies ? ', тобто по одній копії з оригіналу — операційно неправдоподібно' : '');
            }

            $hits[] = [
                'order'  => $this->numberOf($item),
                'detail' => $detail,
            ];
        }

        return $hits;
    }

    // ─── Plumbing ────────────────────────────────────────

    /** @return Builder<Order> */
    private function orders()
    {
        return $this->constrain(Order::query());
    }

    /**
     * The one place the scope of this command is written: never retro, and
     * inside the requested window.
     *
     * Generic because the same scope is applied to an `Order` query and to the
     * inner builder `whereHas('order', …)` hands over. One copy, two callers.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function constrain($query)
    {
        $query->where('is_backdated', false);

        // Through KyivClock, never Carbon::parse: `app.timezone` is UTC, so a
        // date a person typed would be read three hours late — the window that
        // produced R3-7 and R6-1.
        if ($since = $this->option('since')) {
            $query->where('created_at', '>=', KyivClock::instantFromLocal($since.' 00:00:00'));
        }

        if ($until = $this->option('until')) {
            $query->where('created_at', '<=', KyivClock::instantFromLocal($until.' 23:59:59'));
        }

        return $query;
    }

    /**
     * Items of one snapshot `service_type`, with their order loaded.
     *
     * @return Collection<int, OrderItem>
     */
    private function items(string $serviceType)
    {
        return OrderItem::query()
            ->with('order')
            ->whereHas('order', fn ($q) => $this->constrain($q))
            ->get()
            ->filter(fn (OrderItem $i) => ($i->service_snapshot['service_type'] ?? null) === $serviceType)
            ->values();
    }

    /** @return array{0: int|null, 1: int|null} */
    private function parseTierLabel(string $label): array
    {
        if (preg_match('/^(\d+)\+$/u', $label, $m)) {
            return [(int) $m[1], null];
        }

        // The dash is an en dash in the snapshot; accept a hyphen too, because a
        // label typed by hand is exactly the kind of thing that differs.
        if (preg_match('/^(\d+)\s*[–-]\s*(\d+)$/u', $label, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [null, null];
    }

    /** @param array<string, mixed> $params */
    private function diplomaSheets(array $params): int
    {
        $sheets = (int) ($params['diplomas']['qty'] ?? 0)
                + (int) ($params['academic_records']['qty'] ?? 0)
                + (int) ($params['copies']['diploma_copies']['qty'] ?? 0);

        foreach ((array) ($params['supplements'] ?? []) as $supplement) {
            $sheets += (int) ($supplement['sheets'] ?? 0);
        }

        return $sheets;
    }

    /** @return array<int, int> */
    private function divisors(int $n): array
    {
        $out = [];

        for ($k = 2; $k <= $n; $k++) {
            if ($n % $k === 0) {
                $out[] = $k;
            }
        }

        return $out;
    }

    /**
     * The order an item belongs to, typed.
     *
     * Eloquent hands relations back as `Model`, and static analysis is right to
     * refuse `$item->order->status` on that. Named rather than annotated inline
     * so the four call sites read the same.
     */
    private function orderOf(OrderItem $item): Order
    {
        /** @var Order $order */
        $order = $item->order;

        return $order;
    }

    private function numberOf(OrderItem $item): string
    {
        return (string) $this->orderOf($item)->order_number;
    }

    private function signed(float $amount): string
    {
        return ($amount > 0 ? '+' : '').number_format($amount, 2);
    }

    private function fold(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function kyiv(\DateTimeInterface $at): string
    {
        return Carbon::instance($at)->timezone('Europe/Kyiv')->format('d.m.Y H:i');
    }

    private function window(): void
    {
        $since = $this->option('since') ?: 'початку';
        $until = $this->option('until') ?: 'сьогодні';

        $this->line("Вікно: з {$since} до {$until} (київські доби)");
    }

    /** @return array<int, string> */
    private function selectedChecks(): array
    {
        $asked = (array) $this->option('check');

        if ($asked === []) {
            return array_keys(self::CHECKS);
        }

        return array_values(array_intersect(array_keys(self::CHECKS), $asked));
    }

    private function listChecks(): void
    {
        $rows = [];

        foreach (self::CHECKS as $key => $check) {
            $rows[] = [$key, $check['kind'], $check['finding'], $check['title']];
        }

        $this->table(['ключ', 'рід', 'знахідка', 'що шукає'], $rows);
    }

    /**
     * @param  array{title: string, kind: string, finding: string, method: string}  $check
     * @param  array<int, array{order: string, detail: string}>  $hits
     */
    private function renderCheck(array $check, array $hits): void
    {
        $mark = $hits === [] ? '<fg=green>✓</>' : ($check['kind'] === 'DECISIVE' ? '<fg=red>✗</>' : '<fg=yellow>?</>');

        $this->line("{$mark} [{$check['kind']}] {$check['title']} — ".count($hits));

        foreach (array_slice($hits, 0, 25) as $hit) {
            $this->line("    {$hit['order']}: {$hit['detail']}");
        }

        if (count($hits) > 25) {
            $this->line('    … ще '.(count($hits) - 25).'; повний перелік — у --csv');
        }
    }

    private function writeCsv(string $path): void
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            $this->error("Не вдалося відкрити {$path} на запис.");

            return;
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['check', 'kind', 'finding', 'order', 'detail']);

        foreach ($this->findings as $row) {
            fputcsv($handle, [$row['check'], $row['kind'], $row['finding'], $row['order'], $row['detail']]);
        }

        fclose($handle);

        $this->line('');
        $this->info('Записано '.count($this->findings)." рядків у {$path}");
    }
}
