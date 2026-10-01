<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'options'                     => ['required', 'array'],
            'options.*.id'                => ['required', 'exists:service_parameter_options,id'],
            'options.*.inventory_item_id' => ['nullable', 'exists:inventory_items,id'],
            'options.*.inventory_qty'     => ['required', 'numeric', 'min:0'],
        ];
    }
}
