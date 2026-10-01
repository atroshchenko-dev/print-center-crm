<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveSignatoryGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:255'],
            'daily_limit'     => ['nullable', 'integer', 'min:0'],
            'is_active'       => ['boolean'],
            'category_ids'    => ['array'],
            'category_ids.*'  => ['integer', 'exists:service_categories,id'],
        ];
    }
}
