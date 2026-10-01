<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorized via middleware
    }

    public function rules(): array
    {
        return [
            'source_item_id' => ['required', 'exists:inventory_items,id'],
            'target_item_id' => ['required', 'exists:inventory_items,id', 'different:source_item_id'],
            'quantity'        => ['required', 'numeric', 'min:0.01'],
            'ratio'           => ['required', 'numeric', 'min:0.01', 'max:100'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_item_id.different' => 'Джерело і ціль конвертації не можуть бути однаковими.',
            'quantity.min'             => 'Кількість повинна бути більше 0.',
            'ratio.min'                => 'Коефіцієнт повинен бути більше 0.',
        ];
    }
}
