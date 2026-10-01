<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRisoPriceTiersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tiers'                 => ['required', 'array', 'min:1'],
            'tiers.*.id'            => ['required', 'integer', 'exists:riso_price_tiers,id'],
            'tiers.*.min_qty'       => ['required', 'integer', 'min:1'],
            'tiers.*.max_qty'       => ['nullable', 'integer', 'min:1'],
            'tiers.*.cost_per_copy' => ['required', 'numeric', 'min:0'],
        ];
    }
}
