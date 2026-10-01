<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
            'email'         => ['required', 'email', 'unique:users,email'],
            'password'      => ['required', 'string', 'min:8'],
            'role'          => ['required', 'in:admin,executor,manager'],
            'is_active'     => ['boolean'],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => [Rule::in(User::permissionKeys())],
        ];
    }
}
