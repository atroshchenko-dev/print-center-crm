<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * What an order looks like to the person asked to approve it.
 *
 * One author for three consumers: the approval email, the three Blade pages the
 * signatory lands on, and the Telegram line the operators read. Written twice it
 * would be the shape this codebase's audit spent thirty-three rounds removing —
 * two copies of one rule, agreeing until they do not.
 *
 * Grouping by category is not decoration. Until the cart unlocked, an internal
 * order was one category and the letter did not have to say which; a signatory
 * asked about «ЧБ друк + Кольоровий друк + Прошивка» in one flat list cannot see
 * what they are agreeing to.
 */
class ApprovalItemsPresenter
{
    /** Uncategorised work still has to appear; it is the reference book that is missing, not the print run. */
    private const NO_CATEGORY = 'Інше';

    /**
     * Group key for items with no category — distinct from any real category id
     * so it can never collide with one (see the note on {@see itemsWithCategory()}).
     */
    private const NO_CATEGORY_KEY = 'uncategorised';

    /**
     * The order's items, grouped by service category, in the categories' own order.
     *
     * Deliberately carries no money. Owner's decision, 2026-08-20: what a job
     * costs is CRM and accounting information, not the signatory's — they
     * approve the composition and the print run. The `cost` key this used to
     * return is gone rather than merely unrendered, so a future template edit
     * cannot put a figure back in front of a signatory by interpolating it.
     *
     * @return array<int, array{category: string, items: array<int, array{name: string, quantity: int, details: string, material: ?string}>}>
     */
    public function groupedByCategory(Order $order): array
    {
        $groups = [];

        foreach ($this->itemsWithCategory($order) as [$item, $categoryKey, $categoryName, $sortOrder]) {
            $groups[$categoryKey] ??= ['category' => $categoryName, 'sort' => $sortOrder, 'items' => []];
            $groups[$categoryKey]['items'][] = $this->describe($item, $order);
        }

        usort($groups, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return array_map(fn (array $group) => [
            'category' => $group['category'],
            'items'    => $group['items'],
        ], $groups);
    }

    /** The categories this order spans, once each, in the categories' own order. */
    public function categorySummary(Order $order): string
    {
        return implode(' + ', array_column($this->groupedByCategory($order), 'category'));
    }

    /**
     * Each item with the category it sits in (its grouping key and its display
     * name) and the sort key that orders it.
     *
     * The service and its category are both resolved **including a deleted one**
     * — the letter describes work that was ordered, and a service retired since
     * then still names the category it was ordered from. An item with no service
     * at all cannot be placed, and sorts last.
     *
     * The grouping key is the category's id, not its name: `service_categories`
     * has no unique constraint on `name`, and resolving `withTrashed()` on
     * purpose means this method reaches rows an admin has retired. A category
     * renamed by soft-deleting the old row and creating a replacement with the
     * same name — an ordinary admin workflow — is two distinct categories that
     * happen to share a label; keying on the name would fold them into one
     * group, merging their costs and losing whichever `sort_order` was not
     * first in.
     *
     * @return array<int, array{0: OrderItem, 1: int|string, 2: string, 3: int}>
     */
    private function itemsWithCategory(Order $order): array
    {
        $order->loadMissing([
            'items.service'          => fn ($q) => $q->withTrashed(),
            'items.service.category' => fn ($q) => $q->withTrashed(),
        ]);

        // Not `$item->service?->category?->id ?? ...`: Larastan types a
        // BelongsTo's magic property as always-present, so it reports the
        // second nullsafe as dead code — wrongly, since service_category_id
        // is nullable and an uncategorised service is exactly what NO_CATEGORY
        // exists for. Resolving it once and branching on `=== null` checks the
        // same fact and PHPStan verifies it correctly; id/name/sort_order are
        // all NOT NULL columns, so this is not just quieter, it is exactly
        // what the ?? chain meant.
        return $order->items
            ->map(function (OrderItem $item) {
                $category = $item->service?->category;

                return [
                    $item,
                    $category === null ? self::NO_CATEGORY_KEY : $category->id,
                    $category === null ? self::NO_CATEGORY : $category->name,
                    $category === null ? PHP_INT_MAX : $category->sort_order,
                ];
            })
            ->all();
    }

    /**
     * @return array{name: string, quantity: int, details: string, material: ?string}
     */
    private function describe(OrderItem $item, Order $order): array
    {
        $snapshot = $item->service_snapshot;
        $isInternal = $order->type->value === 'internal';
        $details = '';

        // Constructor services
        if (! empty($snapshot['constructor_snapshot'])) {
            $details = collect($snapshot['constructor_snapshot'])
                ->when($isInternal, fn ($c) => $c->where('group_name', '!=', 'Заповненість'))
                ->pluck('option_name')
                ->filter()
                ->map(fn ($name) => str_contains($name, ': ')
                    ? substr($name, strrpos($name, ': ') + 2)
                    : $name)
                ->implode(' · ');
        }
        // RISO services
        elseif (! empty($snapshot['riso_params'])) {
            $rp = $snapshot['riso_params'];
            $parts = [];
            if (! empty($rp['format'])) {
                $parts[] = $rp['format'];
            }
            if (isset($rp['sides'])) {
                $parts[] = $rp['sides'] == 1 ? '1 стор.' : '2 стор.';
            }
            if (! empty($rp['paper_name']) && $rp['paper_name'] !== 'Невідомо') {
                $parts[] = $rp['paper_name'];
            }
            $details = implode(' · ', $parts);
        }
        // Brochures
        elseif (! empty($snapshot['brochure_params'])) {
            $details = $this->describeBrochure($snapshot['brochure_params']);
        }
        // Diplomas
        elseif (! empty($snapshot['diploma_params'])) {
            $details = $this->describeDiploma($snapshot['diploma_params']);
        }

        return [
            'name'     => $item->service_name ?? 'Послуга',
            'quantity' => $item->quantity ?? 1,
            'details'  => $details,
            'material' => $item->material_description,
        ];
    }

    /**
     * What a signatory needs to recognise the brochure they are approving:
     * format, and what each of the two paper stocks is.
     *
     * Same keys the export mapper reads (BackdatedOrdersExport::mapBrochure),
     * which round 14 checked against the real pricing service.
     *
     * @param  array<string, mixed>  $bp
     */
    private function describeBrochure(array $bp): string
    {
        $cover = $bp['cover'] ?? [];
        $block = $bp['block'] ?? [];
        $parts = [];

        if (! empty($bp['format'])) {
            $parts[] = $bp['format'];
        }

        $coverParts = array_filter([
            $this->namedPaper($cover['paper_name'] ?? null),
            $cover['print_mode'] ?? null,
        ]);
        if ($coverParts) {
            $parts[] = 'обкладинка: '.implode(', ', $coverParts);
        }

        $blockParts = array_filter([
            $this->namedPaper($block['paper_name'] ?? null),
            ! empty($block['total_sheets']) ? $block['total_sheets'].' арк.' : null,
        ]);
        if ($blockParts) {
            $parts[] = 'блок: '.implode(', ', $blockParts);
        }

        return implode(' · ', $parts);
    }

    /**
     * A diploma order is a set of counts, and the counts are the thing being
     * approved — a signatory who sees only "Диплом × 1" is approving a number
     * they cannot check.
     *
     * @param  array<string, mixed>  $dp
     */
    private function describeDiploma(array $dp): string
    {
        $parts = [];

        if (! empty($dp['diplomas']['qty'])) {
            $parts[] = 'дипломи: '.$dp['diplomas']['qty'];
        }

        $suppQty = 0;
        $suppSheets = 0;
        foreach ($dp['supplements'] ?? [] as $supp) {
            $suppQty += (int) ($supp['qty'] ?? 0);
            $suppSheets += (int) ($supp['sheets'] ?? 0);
        }
        if ($suppQty > 0) {
            $parts[] = $suppSheets > 0
                ? "додатки: {$suppQty} ({$suppSheets} арк.)"
                : "додатки: {$suppQty}";
        }

        if (! empty($dp['academic_records']['qty'])) {
            $parts[] = 'академдовідки: '.$dp['academic_records']['qty'];
        }

        $copies = $dp['copies'] ?? [];
        if (! empty($copies['diploma_copies']['qty'])) {
            $parts[] = 'копії диплома: '.$copies['diploma_copies']['qty'];
        }
        if (! empty($copies['supplement_copies']['qty'])) {
            $sheets = (int) ($copies['supplement_copies']['sheets'] ?? 0);
            $parts[] = $sheets > 0
                ? 'копії додатка: '.$copies['supplement_copies']['qty']." ({$sheets} арк.)"
                : 'копії додатка: '.$copies['supplement_copies']['qty'];
        }

        return implode(' · ', $parts);
    }

    /**
     * The pricing services write a placeholder when no stock was chosen; it is
     * not a paper name and does not belong in a line the signatory reads.
     */
    private function namedPaper(?string $name): ?string
    {
        return in_array($name, [null, '', 'Не обрано', 'Невідомо'], true) ? null : $name;
    }
}
