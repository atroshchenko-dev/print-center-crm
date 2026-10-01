<?php

declare(strict_types=1);

namespace App\Http\Requests\Constructor;

use Illuminate\Foundation\Http\FormRequest;

class CalculatePriceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'service_id'             => ['required', 'integer', 'exists:services,id'],
            'quantity'               => ['required', 'integer', 'min:1'],
            'selected_option_ids'    => ['array'],
            'selected_option_ids.*'  => ['integer', 'exists:service_parameter_options,id'],
            'customer_paper'         => ['boolean'],
        ];
    }
}
