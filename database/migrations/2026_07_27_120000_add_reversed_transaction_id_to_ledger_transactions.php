<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make "a transaction is reversed at most once" a property of the journal
 * itself (audit finding M-10).
 *
 * Until now the only thing stopping a double reversal was the order status
 * machine: both call sites are gated on PaidIssued and the payment lookup
 * skips rows of type Reversal. Nothing in the ledger said so, and any new code
 * path reaching LedgerService::reverseTransaction() would have bypassed it.
 *
 * The link was already there, but only as prose inside `comment`
 * ("Reversal of TX #123: …"). This turns it into a real column with a unique
 * index, and backfills the historical rows from that comment.
 */
return new class extends Migration
{
    private const PATTERN = 'Reversal of TX #(\d+)';

    public function up(): void
    {
        Schema::table('ledger_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('reversed_transaction_id')->nullable()->after('order_id');

            $table->foreign('reversed_transaction_id')
                ->references('id')
                ->on('ledger_transactions')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });

        $this->backfillFromComments();

        // The actual guard. Partial, so the column stays NULL on every
        // non-reversal row without those colliding with each other.
        DB::statement('
            CREATE UNIQUE INDEX ledger_transactions_reversed_transaction_id_unique
                ON ledger_transactions (reversed_transaction_id)
                WHERE reversed_transaction_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ledger_transactions_reversed_transaction_id_unique');

        Schema::table('ledger_transactions', function (Blueprint $table) {
            $table->dropForeign(['reversed_transaction_id']);
            $table->dropColumn('reversed_transaction_id');
        });
    }

    /**
     * Recover the link from the comment text of existing reversals.
     *
     * The table carries a BEFORE UPDATE trigger (prevent_modification) that
     * rejects every UPDATE, so it has to stand down for the length of the
     * backfill. This is the one place allowed to do that: the rows' financial
     * fields are untouched, only the new column is filled in.
     */
    private function backfillFromComments(): void
    {
        $duplicates = DB::select("
            SELECT (substring(comment from ?))::bigint AS original_id, count(*) AS n
            FROM ledger_transactions
            WHERE type = 'reversal' AND comment ~ ?
            GROUP BY 1
            HAVING count(*) > 1
        ", [self::PATTERN, self::PATTERN]);

        if ($duplicates !== []) {
            $ids = implode(', ', array_map(static fn ($row) => (string) $row->original_id, $duplicates));

            throw new RuntimeException(
                "Cannot add the unique reversal guard: some transactions are already reversed more than once " .
                "(original transaction ids: {$ids}). This is a real accounting problem, not a migration problem — " .
                'reconcile those shifts before deploying, then run the migration again.'
            );
        }

        DB::unprepared('ALTER TABLE ledger_transactions DISABLE TRIGGER trg_ledger_immutable');

        try {
            DB::update("
                UPDATE ledger_transactions
                SET reversed_transaction_id = (substring(comment from ?))::bigint
                WHERE type = 'reversal'
                  AND comment ~ ?
                  AND reversed_transaction_id IS NULL
            ", [self::PATTERN, self::PATTERN]);
        } finally {
            DB::unprepared('ALTER TABLE ledger_transactions ENABLE TRIGGER trg_ledger_immutable');
        }
    }
};
