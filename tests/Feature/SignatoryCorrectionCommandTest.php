<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\UniversityRef;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `orders:fix-signatory` — the one field an issued internal order may still
 * change.
 *
 * An order that has been handed over cannot be edited: the form refuses every
 * terminal status, because its edit path rebuilds the items underneath the
 * order and an issued order has already moved stock and spent a quota. But the
 * signatory is not one of those things. It names the person whose paper request
 * the order was printed against, it moves no money and no paper, and when it is
 * wrong the accountant's only alternative today is to delete the order and
 * enter it again as retro — which mints a new number, leaves the stock 10
 * sheets richer than the shelf, and moves the row into another screen.
 *
 * So: one field, one order, from the console, with the journal entry the form
 * would have written.
 */
class SignatoryCorrectionCommandTest extends TestCase
{
    use RefreshDatabase;

    private function issuedInternalOrder(array $attributes = []): Order
    {
        return Order::factory()->internal()->create(array_merge([
            'order_number'      => 'INT-2607-052',
            'status'            => 'completed_issued',
            'authorized_person' => 'Лапвак С.М.',
            'is_backdated'      => false,
        ], $attributes));
    }

    public function test_it_reassigns_the_order_to_the_signatory_the_reference_carries(): void
    {
        $order = $this->issuedInternalOrder();
        UniversityRef::factory()->create(['full_name' => 'Іщчук Л.В.']);
        $admin = User::factory()->admin()->create();

        $this->artisan('orders:fix-signatory', [
            'order'     => 'INT-2607-052',
            'signatory' => '  іщчук л.в.  ',
            '--by'      => $admin->email,
            '--force'   => true,
        ])->assertExitCode(0);

        // The spelling stored is the reference's own, not the one typed: every
        // quota rule looks the signatory up by this string.
        $this->assertSame('Іщчук Л.В.', $order->fresh()->authorized_person);
    }

    public function test_it_refuses_a_name_the_reference_does_not_carry(): void
    {
        $order = $this->issuedInternalOrder();
        $admin = User::factory()->admin()->create();

        $this->artisan('orders:fix-signatory', [
            'order'     => 'INT-2607-052',
            'signatory' => 'Іщчук Л.В.',
            '--by'      => $admin->email,
            '--force'   => true,
        ])->assertExitCode(1);

        $this->assertSame('Лапвак С.М.', $order->fresh()->authorized_person);
    }

    public function test_it_leaves_the_number_the_date_and_the_money_alone(): void
    {
        $order = $this->issuedInternalOrder(['total_cost' => 7.40]);
        UniversityRef::factory()->create(['full_name' => 'Іщчук Л.В.']);
        $admin = User::factory()->admin()->create();

        $this->artisan('orders:fix-signatory', [
            'order'     => 'INT-2607-052',
            'signatory' => 'Іщчук Л.В.',
            '--by'      => $admin->email,
            '--force'   => true,
        ])->assertExitCode(0);

        $fresh = $order->fresh();
        $this->assertSame('INT-2607-052', $fresh->order_number);
        $this->assertSame(7.40, (float) $fresh->total_cost);
        $this->assertSame(
            $order->created_at->toIso8601String(),
            $fresh->created_at->toIso8601String(),
        );
        $this->assertSame('completed_issued', $fresh->status->value);
    }

    public function test_it_writes_the_correction_to_the_audit_journal_and_the_order_history(): void
    {
        $order = $this->issuedInternalOrder();
        UniversityRef::factory()->create(['full_name' => 'Іщчук Л.В.']);
        $admin = User::factory()->admin()->create();

        $this->artisan('orders:fix-signatory', [
            'order'     => 'INT-2607-052',
            'signatory' => 'Іщчук Л.В.',
            '--by'      => $admin->email,
            '--reason'  => 'Заявка була від іншого підписанта',
            '--force'   => true,
        ])->assertExitCode(0);

        $log = AuditLog::where('event_type', 'order_edited')->latest('id')->first();
        $this->assertNotNull($log, 'A correction outside the UI still belongs in the journal.');
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('Лапвак С.М.', $log->meta['old_signatory']);
        $this->assertSame('Іщчук Л.В.', $log->meta['new_signatory']);
        $this->assertSame('Заявка була від іншого підписанта', $log->meta['reason']);

        $history = OrderStatusHistory::where('order_id', $order->id)->latest('id')->first();
        $this->assertNotNull($history, 'The order card is where an accountant looks first.');
        $this->assertStringContainsString('Іщчук Л.В.', (string) $history->comment);
    }

    public function test_it_takes_the_reconciliation_mark_off(): void
    {
        $order = $this->issuedInternalOrder([
            'is_reconciled' => true,
            'reconciled_at' => now(),
            'reconciled_by' => User::factory()->admin()->create()->id,
        ]);
        UniversityRef::factory()->create(['full_name' => 'Іщчук Л.В.']);
        $admin = User::factory()->admin()->create();

        $this->artisan('orders:fix-signatory', [
            'order'     => 'INT-2607-052',
            'signatory' => 'Іщчук Л.В.',
            '--by'      => $admin->email,
            '--force'   => true,
        ])->assertExitCode(0);

        // Reconciliation is a statement about the order somebody held the paper
        // request next to, and the request names the signatory.
        $fresh = $order->fresh();
        $this->assertFalse($fresh->is_reconciled);
        $this->assertNull($fresh->reconciled_at);
        $this->assertNull($fresh->reconciled_by);
    }

    public function test_it_finds_the_admin_whatever_case_the_email_is_stored_in(): void
    {
        $order = $this->issuedInternalOrder();
        UniversityRef::factory()->create(['full_name' => 'Іщчук Л.В.']);
        // The address as the CRM carries it. Nobody types their own email that
        // way, and Postgres compares it byte for byte.
        $admin = User::factory()->admin()->create(['email' => 'AdminUser@example.edu']);

        $this->artisan('orders:fix-signatory', [
            'order'     => 'INT-2607-052',
            'signatory' => 'Іщчук Л.В.',
            '--by'      => '  adminuser@example.edu ',
            '--force'   => true,
        ])->assertExitCode(0);

        $this->assertSame('Іщчук Л.В.', $order->fresh()->authorized_person);
        // And the journal still names the account, not the string typed at it.
        $this->assertSame(
            $admin->id,
            AuditLog::where('event_type', 'order_edited')->latest('id')->first()?->user_id,
        );
    }

    public function test_it_refuses_when_the_email_matches_more_than_one_account(): void
    {
        $order = $this->issuedInternalOrder();
        UniversityRef::factory()->create(['full_name' => 'Іщчук Л.В.']);
        User::factory()->admin()->create(['email' => 'AdminUser@example.edu']);
        User::factory()->admin()->create(['email' => 'adminuser@example.edu']);

        // Two accounts, one address, and the journal entry has to name one of
        // them: picking either is a guess about who ordered the correction.
        $this->artisan('orders:fix-signatory', [
            'order'     => 'INT-2607-052',
            'signatory' => 'Іщчук Л.В.',
            '--by'      => 'adminuser@example.edu',
            '--force'   => true,
        ])->assertExitCode(1);

        $this->assertSame('Лапвак С.М.', $order->fresh()->authorized_person);
    }

    public function test_it_closes_the_report_with_a_single_full_stop(): void
    {
        $this->issuedInternalOrder();
        // Initials end in a full stop, which is every signatory in the
        // reference.
        UniversityRef::factory()->create(['full_name' => 'Іщчук Л.В.']);
        $admin = User::factory()->admin()->create();

        Artisan::call('orders:fix-signatory', [
            'order'     => 'INT-2607-052',
            'signatory' => 'Іщчук Л.В.',
            '--by'      => $admin->email,
            '--force'   => true,
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString(
            'Готово. Підписант замовлення INT-2607-052 — Іщчук Л.В.',
            $output,
        );
        $this->assertStringNotContainsString('Іщчук Л.В..', $output);
    }

    public function test_it_reports_an_unchanged_order_with_a_single_full_stop(): void
    {
        $this->issuedInternalOrder(['authorized_person' => 'Іщчук Л.В.']);
        UniversityRef::factory()->create(['full_name' => 'Іщчук Л.В.']);
        $admin = User::factory()->admin()->create();

        Artisan::call('orders:fix-signatory', [
            'order'     => 'INT-2607-052',
            'signatory' => 'Іщчук Л.В.',
            '--by'      => $admin->email,
            '--force'   => true,
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('вже підписує Іщчук Л.В.', $output);
        $this->assertStringNotContainsString('Іщчук Л.В..', $output);
    }

    public function test_the_journal_entry_does_not_double_the_full_stop(): void
    {
        $this->issuedInternalOrder();
        UniversityRef::factory()->create(['full_name' => 'Іщчук Л.В.']);
        $admin = User::factory()->admin()->create();

        $this->artisan('orders:fix-signatory', [
            'order'     => 'INT-2607-052',
            'signatory' => 'Іщчук Л.В.',
            '--by'      => $admin->email,
            '--reason'  => 'Заявка була від іншого підписанта',
            '--force'   => true,
        ])->assertExitCode(0);

        // The journal is read by the accountant reconciling the paper request,
        // and it carries the same sentence the console prints.
        $description = (string) AuditLog::where('event_type', 'order_edited')->latest('id')->first()?->description;
        $this->assertStringNotContainsString('Іщчук Л.В..', $description);
        $this->assertStringContainsString('Іщчук Л.В. Reason: Заявка була від іншого підписанта', $description);
    }

    public function test_the_history_comment_does_not_double_the_full_stop(): void
    {
        $order = $this->issuedInternalOrder();
        UniversityRef::factory()->create(['full_name' => 'Іщчук Л.В.']);
        $admin = User::factory()->admin()->create();

        $this->artisan('orders:fix-signatory', [
            'order'     => 'INT-2607-052',
            'signatory' => 'Іщчук Л.В.',
            '--by'      => $admin->email,
            '--reason'  => 'Заявка була від іншого підписанта',
            '--force'   => true,
        ])->assertExitCode(0);

        $comment = (string) OrderStatusHistory::where('order_id', $order->id)->latest('id')->first()?->comment;
        $this->assertStringNotContainsString('Іщчук Л.В..', $comment);
        $this->assertStringContainsString('Іщчук Л.В. Причина: Заявка була від іншого підписанта', $comment);
    }

    public function test_it_refuses_an_order_that_is_not_internal(): void
    {
        $order = Order::factory()->commercial()->create([
            'order_number'      => 'COM-2607-052',
            'status'            => 'paid_issued',
            'authorized_person' => null,
        ]);
        UniversityRef::factory()->create(['full_name' => 'Іщчук Л.В.']);
        $admin = User::factory()->admin()->create();

        $this->artisan('orders:fix-signatory', [
            'order'     => 'COM-2607-052',
            'signatory' => 'Іщчук Л.В.',
            '--by'      => $admin->email,
            '--force'   => true,
        ])->assertExitCode(1);

        $this->assertNull($order->fresh()->authorized_person);
    }
}
