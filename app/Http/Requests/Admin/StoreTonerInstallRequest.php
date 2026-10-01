<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreTonerInstallRequest extends FormRequest
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
            'quantity'          => ['required', 'numeric', 'min:0.0001'],
            'notes'             => ['nullable', 'string', 'max:255'],
        ];
    }
}
