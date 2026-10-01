<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Service;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects services whose category is not offered for the submitted order type.
 *
 * ServiceCategory::available_for was only ever used to filter the operator's
 * UI; nothing enforced it server-side. Categories marked internal-only
 * (Дипломи/Додатки, Брошури, Візитівки, Тиражування) price commercial orders
 * at 0, so a request crafted outside the interface could register a
 * commercial sale for nothing.
 */
class ServiceAvailableForOrderType implements ValidationRule
{
    public function __construct(private readonly ?string $orderType) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->orderType || ! $value) {
            return;
        }

        $service = Service::with('category')->find($value);

        if (! $service || ! $service->category) {
            return; // exists:services,id reports a missing service
        }

        $availableFor = $service->category->available_for ?? [];

        if (! in_array($this->orderType, (array) $availableFor, true)) {
            $fail("Послуга «{$service->name}» недоступна для замовлень типу «{$this->orderType}».");
        }
    }
}
