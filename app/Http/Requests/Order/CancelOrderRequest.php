<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'reason'             => ['required', 'string', 'min:5', 'max:1000'],
            'version'            => ['required', 'integer', 'min:1'],
            'is_technical_defect'=> ['boolean'],
        ];
    }
}
