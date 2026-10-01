<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveServiceCategoryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:255'],
            'sort_order'      => ['integer'],
            'is_active'       => ['boolean'],
            'available_for'   => ['required', 'array', 'min:1'],
            'available_for.*' => ['string', 'in:internal,commercial'],
        ];
    }
}
