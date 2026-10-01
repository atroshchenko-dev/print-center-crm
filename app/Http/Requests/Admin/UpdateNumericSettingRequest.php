<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNumericSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:admin middleware handles authorization
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key'   => ['required', 'string', 'in:large_order_threshold,cash_discrepancy_threshold,riso_commercial_markup,approval_ttl_hours'],
            'value' => ['required', 'numeric', 'min:0'],
        ];
    }
}
