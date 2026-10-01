<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class BatchUpdateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_ids'   => 'required|array|min:1|max:50',
            'order_ids.*' => 'integer|exists:orders,id',
            'status'      => 'required|string|in:in_progress,completed_issued',
        ];
    }
}
