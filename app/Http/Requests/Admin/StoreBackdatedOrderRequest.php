<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreBackdatedOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === \App\Enums\UserRole::Admin;
    }

    public function rules(): array
    {
        return [
            // Not after today *in Kyiv*. Bare `today` resolves in
            // config('app.timezone') — UTC — so between midnight and 03:00 the
            // date the admin is living in was refused as being in the future.
            // That window is the working day here, not an edge case: the
            // auto-close at 03:00 exists because of it.
            'order_date'                        => ['required', 'date', 'before_or_equal:' . today(\App\Support\KyivClock::TZ)->toDateString()],
            'authorized_person'                 => ['required', 'string', 'max:255'],
            'cost_center'                       => ['nullable', 'string', 'max:255'],
            'limit_exceeded'                    => ['boolean'],
            'items'                             => ['required', 'array', 'min:1'],
            'items.*.service_id'                => ['required', 'integer', 'exists:services,id'],
            'items.*.quantity'                  => ['required', 'integer', 'min:1'],
            'items.*.selected_option_ids'       => ['array'],
            'items.*.selected_option_ids.*'     => ['integer', 'exists:service_parameter_options,id'],
            'items.*.riso_format'               => ['sometimes', 'in:A3,A4'],
            'items.*.riso_sides'                => ['sometimes', 'integer', 'in:1,2'],
            'items.*.riso_originals'            => ['sometimes', 'integer', 'min:1'],
            'items.*.riso_paper_id'             => ['sometimes', 'nullable', 'integer', 'exists:inventory_items,id'],
            'items.*.material_description'      => ['nullable', 'string', 'max:500'],
            'items.*.customer_paper'             => ['boolean'],
            // Brochure fields
            'items.*.brochure_format'           => ['sometimes', 'string', 'in:А4,А5'],
            'items.*.brochure_cover_paper_id'   => ['sometimes', 'nullable', 'integer', 'exists:inventory_items,id'],
            'items.*.brochure_cover_mode'       => ['sometimes', 'string', 'in:1+0,1+1,4+0,4+4'],
            'items.*.brochure_block_paper_id'   => ['sometimes', 'nullable', 'integer', 'exists:inventory_items,id'],
            'items.*.brochure_block_entries'    => ['sometimes', 'array', 'min:1'],
            'items.*.brochure_block_entries.*.mode'   => ['required_with:items.*.brochure_block_entries', 'string', 'in:1+0,1+1,4+0,4+4'],
            'items.*.brochure_block_entries.*.sheets' => ['required_with:items.*.brochure_block_entries', 'integer', 'min:1'],
            // Diploma fields
            'items.*.diploma_params'                  => ['sometimes', 'array'],
            'items.*.diploma_params.diplomas'          => ['sometimes', 'array'],
            'items.*.diploma_params.diplomas.qty'      => ['sometimes', 'integer', 'min:0'],
            'items.*.diploma_params.supplements'       => ['sometimes', 'array'],
            'items.*.diploma_params.supplements.*.type'   => ['sometimes', 'string'],
            'items.*.diploma_params.supplements.*.label'  => ['sometimes', 'string'],
            'items.*.diploma_params.supplements.*.qty'    => ['sometimes', 'integer', 'min:0'],
            'items.*.diploma_params.supplements.*.blocks' => ['sometimes', 'array'],
            'items.*.diploma_params.supplements.*.blocks.*.sheets' => ['sometimes', 'integer', 'min:0'],
            'items.*.diploma_params.supplements.*.blocks.*.mode'   => ['sometimes', 'string', 'in:4+4,4+0,0+0'],
            'items.*.diploma_params.academic_records'     => ['sometimes', 'array'],
            'items.*.diploma_params.academic_records.qty' => ['sometimes', 'integer', 'min:0'],
            'items.*.diploma_params.copies'               => ['sometimes', 'array'],
            'items.*.diploma_params.copies.diploma_copies'         => ['sometimes', 'array'],
            'items.*.diploma_params.copies.diploma_copies.qty'     => ['sometimes', 'integer', 'min:0'],
            'items.*.diploma_params.copies.diploma_copies.mode'    => ['sometimes', 'string', 'in:1+0'],
            'items.*.diploma_params.copies.supplement_copies'          => ['sometimes', 'array'],
            'items.*.diploma_params.copies.supplement_copies.qty'     => ['sometimes', 'integer', 'min:0'],
            'items.*.diploma_params.copies.supplement_copies.blocks'   => ['sometimes', 'array'],
            'items.*.diploma_params.copies.supplement_copies.blocks.*.sheets' => ['sometimes', 'integer', 'min:0'],
            'items.*.diploma_params.copies.supplement_copies.blocks.*.mode'   => ['sometimes', 'string', 'in:1+0,1+1'],
        ];
    }
}
