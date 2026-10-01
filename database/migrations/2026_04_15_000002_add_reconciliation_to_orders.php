<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_reconciled')->default(false)->after('is_technical_defect');
            $table->timestamp('reconciled_at')->nullable()->after('is_reconciled');
            $table->unsignedBigInteger('reconciled_by')->nullable()->after('reconciled_at');

            $table->foreign('reconciled_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['reconciled_by']);
            $table->dropColumn(['is_reconciled', 'reconciled_at', 'reconciled_by']);
        });
    }
};
