<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('click_cost', 10, 4)->comment('Cost per printer click/unit');

            // Delayed price feature (TZ §3.2)
            $table->decimal('pending_click_cost', 10, 4)->nullable()->comment('Upcoming price, pending activation');
            $table->timestamp('pending_activated_at')->nullable()->comment('UTC timestamp when pending price activates');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
