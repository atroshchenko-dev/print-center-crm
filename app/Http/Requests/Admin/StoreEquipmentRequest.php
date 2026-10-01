<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreEquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // EnsureRole middleware handles authorization
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:255'],
            'serial_number'   => ['nullable', 'string', 'max:100'],
            'type'            => ['required', 'in:bw,color,riso'],
            'initial_counter' => ['nullable', 'integer', 'min:0'],
            'has_counter'     => ['boolean'],
            'is_active'       => ['boolean'],
        ];
    }
}
