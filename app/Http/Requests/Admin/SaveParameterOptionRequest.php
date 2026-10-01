<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveParameterOptionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'max:255'],
            'price_markup'          => ['required', 'numeric', 'min:0'],
            'inventory_item_id'     => ['nullable', 'exists:inventory_items,id'],
            'inventory_qty'         => ['numeric', 'min:0'],
            'counter_type'          => ['nullable', 'in:bw,color,riso,none'],
            'clicks_per_unit'       => ['integer', 'min:0'],
            'is_active'             => ['boolean'],
            'depends_on'            => ['nullable', 'array'],
            'depends_on.group_id'   => ['required_with:depends_on', 'integer'],
            'depends_on.option_ids' => ['required_with:depends_on', 'array'],
        ];
    }
}
