<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreRisoPriceTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'min_qty'       => ['required', 'integer', 'min:1'],
            'max_qty'       => ['nullable', 'integer', 'min:1'],
            'cost_per_copy' => ['required', 'numeric', 'min:0'],
        ];
    }
}
