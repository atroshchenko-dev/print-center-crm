<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreCounterAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'equipment_id'  => ['required', 'integer', 'exists:equipment,id'],
            'counter_value' => ['required', 'integer', 'min:0'],
            'reason'        => ['required', 'string', 'max:500'],
        ];
    }
}
