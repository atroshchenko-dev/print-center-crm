<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mark approvals whose signatory data has been removed (audit finding M-7).
 *
 * Without this column the retention job would have to recognise its own
 * placeholder text to know what it had already processed — brittle, and it
 * would quietly start over the day someone edits the wording. The timestamp
 * also answers "when was this person's data removed", which is the question
 * a data-subject request actually asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_approvals', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->after('expires_at');
            $table->index('anonymized_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_approvals', function (Blueprint $table) {
            $table->dropIndex(['anonymized_at']);
            $table->dropColumn('anonymized_at');
        });
    }
};
