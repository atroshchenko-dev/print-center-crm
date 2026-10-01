<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        // Note: 'type' is intentionally omitted — service type (static/constructor)
        // cannot be changed after creation to preserve historical order data integrity.
        return [
            'name'                   => ['required', 'string', 'max:255'],
            'base_price_commercial'  => ['required', 'numeric', 'min:0'],
            'base_price_cost'        => ['required', 'numeric', 'min:0'],
            'counter_type'           => ['nullable', 'in:bw,color,riso,none'],
            'clicks_per_unit'        => ['integer', 'min:0'],
            'is_active'              => ['boolean'],
            'service_category_id'    => ['nullable', 'exists:service_categories,id'],
        ];
    }
}
