<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\UniversityRef;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reassign one internal order to the signatory it was actually printed for.
 *
 * The edit form refuses every terminal status, and rightly so: its update path
 * soft-deletes the items and rebuilds them, which an issued order cannot
 * survive — the stock is off the shelf and the quota is spent. The signatory is
 * none of that. It is the name of the person whose paper request the order sits
 * against, it moves no money and no paper, and the row it lives on is the one
 * the accountant reconciles against that request.
 *
 * Without this the only correction available for an issued order was: delete it
 * (which returns the paper to the shelf and the clicks to the quota) and enter
 * it again through the retro module (which returns neither, and mints a new
 * number the paper request is not filed under). Three wrong numbers to fix one
 * wrong name.
 *
 * Deliberately a console command and not a route: it is the rare correction,
 * and every use of it names the admin who ordered it and the reason they gave.
 */
class FixOrderSignatoryCommand extends Command
{
    protected $signature = 'orders:fix-signatory
                            {order : Номер замовлення (наприклад INT-2607-052)}
                            {signatory : ПІБ підписанта з довідника}
                            {--by= : Email користувача, від імені якого робиться правка}
                            {--reason= : Причина правки для журналу}
                            {--force : Не питати підтвердження}';

    protected $description = 'Reassign an internal order to another signatory, with an audit trail';

    public function __construct(private readonly AuditService $auditService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $order = Order::where('order_number', $this->argument('order'))->first();

        if (! $order) {
            $this->error("Замовлення «{$this->argument('order')}» не знайдено.");

            return self::FAILURE;
        }

        if ($order->type !== OrderType::Internal) {
            $this->error('Підписант є лише у внутрішніх замовлень.');

            return self::FAILURE;
        }

        // Matched the way the signatory below is matched — LOWER(TRIM(…)) —
        // because Postgres compares byte for byte and nobody types their own
        // address the way the CRM happens to carry it.
        $by = (string) $this->option('by');
        $users = User::whereRaw('LOWER(TRIM(email)) = ?', [$this->fold($by)])->get();

        if ($users->isEmpty()) {
            $this->error('Вкажіть --by з email користувача, від імені якого робиться правка.');

            return self::FAILURE;
        }

        // Every entry this command writes names the admin who ordered the
        // correction, so two accounts behind one address is a question for a
        // human, not a first() away.
        if ($users->count() > 1) {
            $this->error("Email «{$by}» належить більш ніж одному акаунту — вкажіть той, від якого правка.");

            return self::FAILURE;
        }

        $user = $users->first();

        // The reference is matched the way the unique index matches — LOWER(TRIM(…))
        // — and the row's own spelling is what gets stored. Every quota rule
        // looks the signatory up by this string, so a near-miss spelling would
        // detach the order from the reference while looking perfectly right on
        // the screen.
        $matches = UniversityRef::all()
            ->filter(fn (UniversityRef $ref) => $this->fold($ref->full_name) === $this->fold($this->argument('signatory')));

        if ($matches->isEmpty()) {
            $this->error("Підписант «{$this->argument('signatory')}» відсутній у довіднику.");

            return self::FAILURE;
        }

        if ($matches->count() > 1) {
            $this->error("Підписант «{$this->argument('signatory')}» у довіднику не один — виправте дублі.");

            return self::FAILURE;
        }

        $newName = $matches->first()->full_name;
        $oldName = (string) $order->authorized_person;

        if ($oldName === $newName) {
            $this->info($this->closed("Замовлення {$order->order_number} вже підписує {$newName}"));

            return self::SUCCESS;
        }

        $this->line("{$order->order_number}: {$oldName} → {$newName}");

        if (! $this->option('force') && ! $this->confirm('Змінити підписанта?')) {
            $this->info('Скасовано.');

            return self::SUCCESS;
        }

        $reason = $this->option('reason');

        DB::transaction(function () use ($order, $oldName, $newName, $user, $reason) {
            $order->update(['authorized_person' => $newName]);

            // Same rule the edit form follows: a reconciliation is a statement
            // about the order somebody held the paper request next to, and the
            // request names the signatory.
            if ($order->clearReconciliationAfterEdit()) {
                $this->warn('Звірку знято: замовлення змінилося після неї.');
            }

            $this->auditService->log(
                eventType: 'order_edited',
                user: $user,
                description: $this->closed("Order {$order->order_number} signatory corrected: {$oldName} → {$newName}")
                    .($reason ? " Reason: {$reason}" : ''),
                meta: [
                    'order_id'      => $order->id,
                    'old_signatory' => $oldName,
                    'new_signatory' => $newName,
                    'reason'        => $reason,
                    'source'        => 'console',
                ],
            );

            OrderStatusHistory::record(
                $order,
                $order->status->value,
                $order->status->value,
                $user,
                $this->closed("Підписант: {$oldName} → {$newName}").($reason ? " Причина: {$reason}" : ''),
            );
        });

        $this->info($this->closed("Готово. Підписант замовлення {$order->order_number} — {$newName}"));

        return self::SUCCESS;
    }

    private function fold(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /**
     * Close a sentence that ends on a signatory's name.
     *
     * The reference is nearly all initials, and initials carry their own full
     * stop, so every sentence this command composes — the console lines, the
     * journal entry, the history comment — borrows that stop instead of
     * printing a second one beside it. The reference keeps its own spelling:
     * only the sentence around the name is affected.
     */
    private function closed(string $sentence): string
    {
        return str_ends_with($sentence, '.') ? $sentence : $sentence.'.';
    }
}
