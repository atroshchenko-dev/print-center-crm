<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\OrderApproval;
use App\Models\OrderItem;
use App\Models\ServiceCategory;
use Illuminate\Console\Command;

/**
 * Enforce the personal-data retention policy (audit finding M-7).
 *
 * Before this, nothing in the project ever removed personal data: signatory
 * names, their email addresses and the IP they answered from stayed for the
 * lifetime of the database, with no schedule and no way to answer "how long
 * do you keep this".
 *
 * Runs nightly. Idempotent — anonymized_at marks what is already done, so a
 * second run in the same night is a no-op.
 */
class PrunePersonalData extends Command
{
    protected $signature = 'privacy:prune
                            {--days= : Override the retention window from config/privacy.php}
                            {--dry-run : Report what would be removed, change nothing}';

    protected $description = 'Anonymise personal data past its retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('privacy.approval_retention_days'));

        if ($days < 1) {
            $this->error('Retention window must be at least 1 day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Retention window: {$days} days (cutoff {$cutoff->toDateString()})");

        $itemDays = (int) ($this->option('days') ?? config('privacy.order_item_retention_days'));
        $itemCutoff = now()->subDays(max(1, $itemDays));

        $due = OrderApproval::withTrashed()->duePersonalDataRemoval($cutoff);
        $dueItems = OrderItem::withTrashed()->duePersonalDataRemoval($itemCutoff);

        $count = (clone $due)->count();
        $itemCount = (clone $dueItems)->count();

        // The business-card rule is keyed on a category name, so a rename in the
        // admin would silently switch it off — the L-6 hazard. Say so rather
        // than report a quiet zero.
        if (! ServiceCategory::where('name', ServiceCategory::BUSINESS_CARDS)->exists()) {
            $this->warn('No category named "'.ServiceCategory::BUSINESS_CARDS.'" — business-card names are NOT being pruned.');
        }

        if ($count === 0 && $itemCount === 0) {
            $this->line('Nothing past its retention window.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("{$count} approval(s) and {$itemCount} business-card line(s) would have their personal data removed. Nothing changed.");

            return self::SUCCESS;
        }

        // Chunked by id: anonymize() writes anonymized_at, which is part of the
        // scope's own filter, so a plain chunk() would skip rows as the result
        // set shifts underneath it. The same applies to the item pass, where
        // the placeholder itself is what removes a row from the scope.
        $processed = 0;

        $due->chunkById(200, function ($approvals) use (&$processed): void {
            foreach ($approvals as $approval) {
                $approval->anonymize();
                $processed++;
            }
        });

        $itemsProcessed = 0;

        $dueItems->chunkById(200, function ($items) use (&$itemsProcessed): void {
            foreach ($items as $item) {
                $item->anonymizeDescription();
                $itemsProcessed++;
            }
        });

        // The removal itself is an event worth recording — but only in
        // aggregate. Writing the affected emails here would defeat the point.
        AuditLog::record(
            eventType: 'privacy_prune',
            user: null,
            description: "Anonymised signatory data on {$processed} approval(s) and the customer name on {$itemsProcessed} business-card line(s), older than {$days} days",
            meta: [
                'retention_days' => $days,
                'item_retention_days' => $itemDays,
                'cutoff' => $cutoff->toDateTimeString(),
                'approvals' => $processed,
                'order_items' => $itemsProcessed,
            ],
        );

        $this->info("Anonymised {$processed} approval(s) and {$itemsProcessed} business-card line(s).");

        return self::SUCCESS;
    }
}
