<?php

declare(strict_types=1);

use App\Models\Service;
use App\Models\ServiceParameterGroup;
use Illuminate\Database\Migrations\Migration;

/**
 * Make the "Обкладинка" group required for hard binding ONLY.
 * Channel + Cover binding type requires at least one cover to be selected.
 * Soft binding cover group remains optional (is_required=false).
 */
return new class extends Migration
{
    public function up(): void
    {
        $service = Service::where('name', 'Палітурка тверда')->first();
        if ($service) {
            ServiceParameterGroup::where('service_id', $service->id)
                ->where('name', 'Обкладинка')
                ->update(['is_required' => true]);
        }
    }

    public function down(): void
    {
        $service = Service::where('name', 'Палітурка тверда')->first();
        if ($service) {
            ServiceParameterGroup::where('service_id', $service->id)
                ->where('name', 'Обкладинка')
                ->update(['is_required' => false]);
        }
    }
};
