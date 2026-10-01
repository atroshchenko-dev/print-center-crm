<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\KyivClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The activation field is a Kyiv wall clock — the form says so. Convert it
     * here, before the rules run, and everything downstream gets one instant:
     *
     *  - `after:now` compares UTC to UTC. It used to compare the typed Kyiv
     *    clock against a UTC `now()`, which let anything up to three hours in
     *    the past through as "scheduled" — and the next scheduler run then
     *    activated it, in the middle of the working day.
     *  - store() and update() write the same value. The conversion used to
     *    live in update() alone, so the same form posting to the other route
     *    stored the price change three hours late.
     */
    protected function prepareForValidation(): void
    {
        $local = $this->input('pending_activated_at');

        if (is_string($local) && $local !== '') {
            $this->merge([
                'pending_activated_at' => KyivClock::instantFromLocal($local)->toDateTimeString(),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Owner's decision, 2026-08-01 (CLOSEOUT §1.8): two active
            // materials of one counter type must not exist — not "one of them
            // wins", but "there is only ever one". The partial unique index
            // enforces it; this is what turns the collision into a sentence
            // under the field instead of a 500 out of PostgreSQL.
            //
            // Only when the row is being saved **active**: a deactivated
            // material is history, and the index does not count it either.
            'counter_type' => $this->boolean('is_active')
                ? [
                    'required',
                    'in:bw,color,riso',
                    Rule::unique('materials', 'counter_type')
                        ->where('is_active', true)
                        ->whereNull('deleted_at')
                        ->ignore($this->route('material')?->id),
                ]
                : ['required', 'in:bw,color,riso'],
            'click_cost' => ['required', 'numeric', 'min:0'],
            // The two halves of a scheduled price travel together or not at
            // all. PriceSchedulerService needs both to be set before it will
            // activate anything, so a price with no time is a change that never
            // lands, and a time with no price is nothing at all — and neither
            // was refused. The audit line names both, too, and reached for a
            // key that was not there.
            'pending_click_cost'   => ['nullable', 'numeric', 'min:0', 'required_with:pending_activated_at'],
            'pending_activated_at' => ['nullable', 'date', 'after:now', 'required_with:pending_click_cost'],
            'is_active'            => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'counter_type.unique' => 'Для цього типу лічильника вже є активне покриття. Спершу деактивуйте старе — вартість кліка має одне джерело.',
        ];
    }
}
