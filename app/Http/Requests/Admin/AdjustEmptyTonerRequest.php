<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdjustEmptyTonerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission:inventory middleware handles authorization
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'inventory_item_id' => ['required', 'exists:inventory_items,id'],
            'quantity_change'   => ['required', 'numeric'],
            'notes'             => ['nullable', 'string', 'max:255'],
        ];
    }
}
