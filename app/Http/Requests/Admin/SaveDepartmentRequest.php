<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Rules\UniqueReferenceName;
use Illuminate\Foundation\Http\FormRequest;

class SaveDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                new UniqueReferenceName('departments', 'name', $this->route('department')?->id),
            ],
            'type'      => ['required', 'in:department,project'],
            'is_active' => ['boolean'],
        ];
    }
}
