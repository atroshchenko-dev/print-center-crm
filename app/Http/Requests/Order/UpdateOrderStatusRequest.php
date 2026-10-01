<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'status'         => ['required', 'string', Rule::enum(OrderStatus::class)],
            'version'        => ['required', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'in:cash,card'],
        ];
    }
}
