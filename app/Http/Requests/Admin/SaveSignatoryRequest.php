<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Rules\UniqueReferenceName;
use Illuminate\Foundation\Http\FormRequest;

class SaveSignatoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => [
                'required',
                'string',
                'max:255',
                new UniqueReferenceName('university_refs', 'full_name', $this->route('signatory')?->id),
            ],
            'position'           => ['nullable', 'string', 'max:255'],
            'email'              => ['nullable', 'email', 'max:255'],
            'is_active'          => ['boolean'],
            'signatory_group_id' => ['nullable', 'integer', 'exists:signatory_groups,id'],
        ];
    }
}
