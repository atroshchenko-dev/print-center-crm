<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveSignatoryCostCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // доступ гейтить сама група маршрутів адмінки
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ];
    }
}
