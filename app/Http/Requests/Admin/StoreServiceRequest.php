<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'                   => ['required', 'string', 'max:255'],
            'type'                   => ['required', 'in:static,constructor'],
            'base_price_commercial'  => ['required', 'numeric', 'min:0'],
            'base_price_cost'        => ['required', 'numeric', 'min:0'],
            'counter_type'           => ['nullable', 'in:bw,color,riso,none'],
            'clicks_per_unit'        => ['integer', 'min:0'],
            'is_active'              => ['boolean'],
            'service_category_id'    => ['nullable', 'exists:service_categories,id'],
        ];
    }
}
