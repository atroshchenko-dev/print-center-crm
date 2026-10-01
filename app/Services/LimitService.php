<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Department;
use App\Models\DepartmentLimit;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\Shift;
use App\Models\UniversityRef;
use App\Models\User;
use App\Support\KyivClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LimitService
 *
 * Server-side enforcement of print quotas.
 * TZ §3.1 + §4.2: limits are checked on the backend, not trusted from the client.
 * TZ §4.3: limits returned on technical defect cancellation.
 *
 * Two quotas live here and they are not the same one:
 *
 *  - **by cost centre** (`Department` + `DepartmentLimit`), a stored monthly
 *    counter of black-and-white clicks, incremented when an order is issued.
 *    **It has never been configurable and is not used** — nothing in the
 *    product creates a `department_limits` row, so `checkLimit()` below has
 *    answered «no limit» to every order ever placed, and `incrementUsage()` has
 *    returned without writing (audit R33-2, counted on production 2026-08-04).
 *    Recorded as not used by the owner, 2026-08-04 (CLOSEOUT §1.12); the
 *    monthly reset is out of the schedule for the same reason. The code stays
 *    where it is because turning the quota on is a form field away, and the
 *    rule it would enforce is already written here correctly;
 *  - **by signatory group** (`signatory_groups.daily_limit`), all copies of any
 *    colour, and no stored counter at all — the usage is summed from the orders
 *    themselves each time it is asked for. That costs a query and buys three
 *    things: no migration, no reset job to forget, and a figure that cannot
 *    drift from the orders it claims to describe.
 */
class LimitService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Ask all three rules about an order that is **already saved**, record what
     * they find, and set `limit_exceeded` from the answer.
     *
     * This lives here, and takes nothing but the order, because it has to be
     * callable from more than one place. Until round 17 it was a private method
     * of `OrderCreationService` — so an order placed inside its quota and then
     * edited tenfold passed every rule silently, and the `limit_exceeded` column
     * held whatever checkbox the browser last posted. The comment three lines
     * above this class says limits are checked on the backend and not trusted
     * from the client; on the edit path that had never been true.
     *
     * Nothing here is an increment, so calling it again on the same order is
     * safe and gives the same answer: the group pool is summed from the orders
     * each time, the cost-centre counter is only moved when an order is issued,
     * and the category rule is a function of the items as they now stand. That
     * is why an edit needs no separate «did this edit make it worse» rule — it
     * re-asks the same question of the order as it now is.
     *
     * All three report and none refuse: ТЗ §3 makes a breached limit «візуальне
     * попередження, збереження дозволено, факт перевищення фіксується в лозі»,
     * and the owner's decision of 2026-07-31 put the group pool and the category
     * rule on the same footing.
     *
     * @return array<int, string> what the operator has to be told, if anything
     */
    public function recordForOrder(Order $order, User $user, ?Shift $shift): array
    {
        // A commercial order has no quota to breach, so the flag has no meaning
        // on one — and it is not the browser's to set. The checkbox that used to
        // carry it is gone from both order forms; the retro module keeps its
        // own, because retro deliberately runs no limit check at all.
        if ($order->type !== OrderType::Internal) {
            if ($order->limit_exceeded) {
                $order->update(['limit_exceeded' => false]);
            }

            return [];
        }

        $items = $order->items()->get();
        $exceeded = false;
        $warnings = [];

        if (! empty($order->cost_center)) {
            $check = $this->checkLimit($order->cost_center, (int) $items->sum('bw_clicks'));

            if ($check['exceeded']) {
                $exceeded = true;
                $warnings[] = "Ліміт підрозділу «{$order->cost_center}» перевищено.";

                $this->auditService->log(
                    'limit_exceeded',
                    $user,
                    "Limit exceeded on order {$order->order_number}",
                    $shift?->id,
                    ['order_id' => $order->id, 'cost_center' => $order->cost_center],
                );
            }
        }

        $group = $this->checkSignatoryLimit($order->authorized_person);

        if ($group['exceeded']) {
            $exceeded = true;
            $warnings[] = "Місячний ліміт групи «{$group['group']}» вичерпано: {$group['usage']} з {$group['limit']} копій.";

            $this->auditService->log(
                'limit_exceeded',
                $user,
                "Signatory group limit exceeded on order {$order->order_number}",
                $shift?->id,
                [
                    'order_id'          => $order->id,
                    'authorized_person' => $order->authorized_person,
                    'signatory_group'   => $group['group'],
                    'monthly_limit'     => $group['limit'],
                    'usage'             => $group['usage'],
                ],
            );
        }

        $outside = $this->servicesOutsideSignatoryCategories(
            $order->authorized_person,
            $items->pluck('service_id'),
        );

        if ($outside !== []) {
            $warnings[] = 'Послуги поза дозволеними категоріями підписанта: '.implode(', ', $outside).'.';

            $this->auditService->log(
                'category_not_allowed',
                $user,
                "Services outside the signatory's categories on order {$order->order_number}: ".implode(', ', $outside),
                $shift?->id,
                [
                    'order_id'          => $order->id,
                    'authorized_person' => $order->authorized_person,
                    'services'          => $outside,
                ],
            );
        }

        $order->update(['limit_exceeded' => $exceeded]);

        return $warnings;
    }

    /**
     * Check if the department has remaining quota for BW copies.
     *
     * Resolved **including a deleted department**, on the same argument
     * `checkSignatoryLimit()` makes about a deactivated group two methods below:
     * the order still names it, and removing a row from the reference book is
     * not a decision to grant that cost centre unlimited printing. Until round
     * 19 the two quotas in this file disagreed about this — the signatory half
     * said `withTrashed()`, the cost-centre half did not, and a deleted
     * department therefore came back `PHP_INT_MAX` from here.
     *
     * @return array{exceeded: bool, remaining: int, limit: int, current_usage: int}
     */
    public function checkLimit(string $costCenter, int $bwClicksRequested): array
    {
        $department = Department::withTrashed()->where('name', $costCenter)->first();

        if (! $department) {
            return ['exceeded' => false, 'remaining' => PHP_INT_MAX, 'limit' => 0, 'current_usage' => 0];
        }

        $limit = DepartmentLimit::where('department_id', $department->id)
            ->where('limit_type', 'bw_copies')
            ->first();

        if (! $limit) {
            return ['exceeded' => false, 'remaining' => PHP_INT_MAX, 'limit' => 0, 'current_usage' => 0];
        }

        $remaining = max(0, $limit->monthly_limit - $limit->current_usage);
        $exceeded = ($limit->current_usage + $bwClicksRequested) > $limit->monthly_limit;

        return [
            'exceeded'      => $exceeded,
            'remaining'     => $remaining,
            'limit'         => $limit->monthly_limit,
            'current_usage' => $limit->current_usage,
        ];
    }

    /**
     * How the signatory's group is doing against its monthly pool, right now.
     *
     * Counted from the orders, not from a stored total, and counted **after**
     * the order in question is written — so the caller does not add anything to
     * the answer, and there is no way for a counter to disagree with the orders
     * it was supposed to be counting.
     *
     * Copies of every colour: the field says «копій», not «ч/б копій». That is
     * the one place this differs from the cost-centre quota, whose `limit_type`
     * is literally `bw_copies`.
     *
     * Retro orders are out (`is_backdated`), as they are out of every other
     * limit path — they record work from a period the quota has already been
     * spent and reset for. Cancelled orders are out; deleted orders are out.
     *
     * The group is resolved **including a deactivated one**: a signatory still
     * points at it, deactivating a group is not a decision to grant unlimited
     * printing, and since round 15 the admin can see such rows on the page.
     *
     * @return array{limited: bool, exceeded: bool, limit: int, usage: int, remaining: int, group: string|null}
     */
    public function checkSignatoryLimit(?string $authorizedPerson): array
    {
        $none = ['limited' => false, 'exceeded' => false, 'limit' => 0, 'usage' => 0, 'remaining' => PHP_INT_MAX, 'group' => null];

        if (empty($authorizedPerson)) {
            return $none;
        }

        $signatory = UniversityRef::withTrashed()
            ->with(['group' => fn ($q) => $q->withTrashed()])
            ->where('full_name', $authorizedPerson)
            ->first();

        $group = $signatory?->group;
        $limit = $group?->monthlyLimit();

        if ($limit === null) {
            return $none;
        }

        $usage = $this->groupUsageThisMonth($group->id);

        return [
            'limited'   => true,
            'exceeded'  => $usage > $limit,
            'limit'     => $limit,
            'usage'     => $usage,
            'remaining' => max(0, $limit - $usage),
            'group'     => $group->name,
        ];
    }

    /**
     * Copies printed this Kyiv month for every signatory of one group.
     */
    private function groupUsageThisMonth(int $groupId): int
    {
        [$from, $to] = KyivClock::monthWindow();

        return $this->groupUsageInWindow($groupId, $from, $to);
    }

    /**
     * The same sum over an arbitrary window — one rule, one author.
     *
     * Public because `audit:orders` re-asks this question of **past** months to
     * find orders that breached the pool before round 17 taught the edit path to
     * check. A reviewer that carried its own copy of this query would be the
     * defect this audit spent thirty-three rounds removing: a
     * money rule written twice, agreeing until it does not.
     *
     * @param  \DateTimeInterface|string  $from
     * @param  \DateTimeInterface|string  $to
     */
    public function groupUsageInWindow(int $groupId, $from, $to): int
    {
        $names = UniversityRef::withTrashed()
            ->where('signatory_group_id', $groupId)
            ->pluck('full_name');

        if ($names->isEmpty()) {
            return 0;
        }

        return (int) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.type', OrderType::Internal->value)
            ->where('orders.is_backdated', false)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereIn('orders.authorized_person', $names)
            ->whereBetween('orders.created_at', [$from, $to])
            ->sum(DB::raw('COALESCE(order_items.bw_clicks, 0) + COALESCE(order_items.color_clicks, 0) + COALESCE(order_items.riso_clicks, 0)'));
    }

    /**
     * Which of an order's services the signatory's group is not allowed.
     *
     * ТЗ §3 says the category list is limited to what the chosen person may
     * order. `Orders/Create.vue` does that — it filters the tabs and even drops
     * cart items that stop being allowed — and until round 15 the server did
     * not look at all, in a file whose neighbour reads "limits are checked on
     * the backend, not trusted from the client".
     *
     * It reports rather than refuses, by the owner's decision of 2026-07-31 and
     * for the same reason the quota reports: ТЗ makes the limit a warning that
     * still saves and still records, and a hard refusal here would also stop an
     * admin entering an old order whose signatory has since changed group.
     *
     * A group with no categories configured restricts nothing — that is what an
     * empty list means on the admin form.
     *
     * A service with no category counts as outside, which is the rule the page
     * already applies: `displayCategories` drops the «Інше» tab entirely once a
     * signatory narrows the list. The server mirrors the client here rather than
     * inventing a stricter rule of its own.
     *
     * Both the service and its category are resolved **including a deleted
     * one**: an item retired since the order was taken still sat outside the
     * signatory's categories when it was ordered, and a lookup that misses it
     * reports fewer lines than the order has. Same argument the two quotas in
     * this file already make about a deleted department and a deactivated group.
     *
     * The name of each offending service carries the category it actually sits
     * in. With one category per order the operator knew which one was meant;
     * once an order mixes several, a bare list of service names makes them map
     * each back to its category by hand.
     *
     * @param  Collection<int, int|null>  $serviceIds
     * @return array<int, string> the offending services, each named with its category
     */
    public function servicesOutsideSignatoryCategories(?string $authorizedPerson, Collection $serviceIds): array
    {
        // Not ->isEmpty(): Larastan's stub for it carries a first()/last()
        // assert-if-true/false pair that misfires specifically when TValue is
        // nullable (as it honestly is here — service_id can be null), reporting
        // this guard as dead code. count() === 0 is the same check without the
        // false positive; @param above is unchanged and was already accurate.
        if (empty($authorizedPerson) || $serviceIds->count() === 0) {
            return [];
        }

        $signatory = UniversityRef::withTrashed()
            ->with(['group' => fn ($q) => $q->withTrashed()->with('categories:id')])
            ->where('full_name', $authorizedPerson)
            ->first();

        $allowed = $signatory?->group?->categories->pluck('id');

        if ($allowed === null || $allowed->isEmpty()) {
            return [];
        }

        $outside = Service::withTrashed()
            ->with(['category' => fn ($q) => $q->withTrashed()])
            ->whereIn('id', $serviceIds->filter()->unique())
            ->where(function ($q) use ($allowed) {
                $q->whereNull('service_category_id')
                    ->orWhereNotIn('service_category_id', $allowed);
            })
            ->get()
            ->map(fn (Service $service) => $service->category
                ? "{$service->name} ({$service->category->name})"
                : "{$service->name} (без категорії)")
            ->all();

        // `order_items.service_id` is nullable, and a line whose service is gone
        // cannot be placed in any category. Dropping it left the operator with a
        // warning that silently described fewer lines than the order has.
        $unresolvable = $serviceIds->filter(fn ($id) => $id === null)->count();

        if ($unresolvable > 0) {
            $outside[] = "позиції без послуги: {$unresolvable} — категорію не перевірити";
        }

        return $outside;
    }

    /**
     * Increment usage after an internal order reaches final status.
     *
     * The deleted department is found here for the reason above, and this is the
     * expensive half of it: the copies are printed and handed over either way,
     * so a lookup that misses leaves the counter describing less print than
     * happened. Same shape as R18-2, arriving by delete instead of rename.
     */
    public function incrementUsage(string $costCenter, int $bwClicks): void
    {
        if ($bwClicks <= 0) {
            return;
        }

        $department = Department::withTrashed()->where('name', $costCenter)->first();
        if (! $department) {
            return;
        }

        DB::transaction(function () use ($department, $bwClicks) {
            $limit = DepartmentLimit::where('department_id', $department->id)
                ->where('limit_type', 'bw_copies')
                ->lockForUpdate()
                ->first();

            if ($limit) {
                $limit->increment('current_usage', $bwClicks);
            }
        });
    }

    /**
     * Return usage on cancellation of issued internal orders (TZ §4.3).
     */
    public function returnUsage(string $costCenter, int $bwClicks): void
    {
        if ($bwClicks <= 0) {
            return;
        }

        $department = Department::withTrashed()->where('name', $costCenter)->first();
        if (! $department) {
            return;
        }

        DB::transaction(function () use ($department, $bwClicks) {
            $limit = DepartmentLimit::where('department_id', $department->id)
                ->where('limit_type', 'bw_copies')
                ->lockForUpdate()
                ->first();

            if ($limit) {
                $limit->decrement('current_usage', min($bwClicks, $limit->current_usage));
            }
        });
    }
}
