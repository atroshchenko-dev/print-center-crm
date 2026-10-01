<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Handled by middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['required', 'email', 'max:255', 'unique:users,email,'.$this->route('user')->id],
            'role'          => ['required', 'in:admin,executor,manager'],
            'is_active'     => ['boolean'],
            'password'      => ['nullable', 'string', 'min:8'],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => [Rule::in(User::permissionKeys())],
        ];
    }
}
