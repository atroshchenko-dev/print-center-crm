<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate shift opening data.
 * Includes optional settlement fields for previous shift reconciliation.
 */
class OpenShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Morning counter readings (for equipment with counters)
            //
            // `distinct` because one machine gets one morning reading. The
            // database says the same thing again, and both are needed: the
            // constraint turns a repeat into a 500, this turns it into a
            // message the operator can read.
            'readings'                  => ['nullable', 'array'],
            'readings.*.equipment_id'   => ['required_with:readings', 'integer', 'exists:equipment,id', 'distinct'],
            'readings.*.counter_value'  => ['required_with:readings', 'integer', 'min:0'],

            // Cash reconciliation for previous shift
            'cash_actual'         => ['nullable', 'numeric', 'min:0'],
            'discrepancy_reason'  => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'readings.*.counter_value.required_with' => 'Необхідно внести показник лічильника.',
            'readings.*.equipment_id.distinct'       => 'Показник для одного апарата вноситься один раз.',
        ];
    }
}
