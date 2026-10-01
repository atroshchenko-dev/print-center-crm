<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Rules\ServiceAvailableForOrderType;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:internal,commercial'],
            'authorized_person' => ['required_if:type,internal', 'nullable', 'string', 'max:255'],
            'initiator' => ['nullable', 'string', 'max:255'],
            'cost_center' => ['nullable', 'string', 'max:255'],
            'is_at_cost' => ['boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.service_id' => ['required', 'integer', 'exists:services,id', new ServiceAvailableForOrderType($this->input('type'))],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.selected_option_ids' => ['array'],
            'items.*.selected_option_ids.*' => ['integer', 'exists:service_parameter_options,id'],
            'items.*.riso_format' => ['sometimes', 'in:A3,A4'],
            'items.*.riso_sides' => ['sometimes', 'integer', 'in:1,2'],
            // The calculator sums A3 sheets per original; without this the server
            // could only round the whole run and quoted a different price.
            'items.*.riso_originals' => ['sometimes', 'integer', 'min:1'],
            'items.*.riso_paper_id' => ['sometimes', 'nullable', 'integer', 'exists:inventory_items,id'],
            'items.*.material_description' => ['nullable', 'string', 'max:500'],
            'items.*.customer_paper' => ['boolean'],
            // Brochure fields
            'items.*.brochure_format' => ['sometimes', 'string', 'in:А4,А5'],
            'items.*.brochure_cover_paper_id' => ['sometimes', 'nullable', 'integer', 'exists:inventory_items,id'],
            'items.*.brochure_cover_mode' => ['sometimes', 'string', 'in:1+0,1+1,4+0,4+4'],
            'items.*.brochure_block_paper_id' => ['sometimes', 'nullable', 'integer', 'exists:inventory_items,id'],
            'items.*.brochure_block_entries' => ['sometimes', 'array', 'min:1'],
            'items.*.brochure_block_entries.*.mode' => ['required_with:items.*.brochure_block_entries', 'string', 'in:1+0,1+1,4+0,4+4'],
            'items.*.brochure_block_entries.*.sheets' => ['required_with:items.*.brochure_block_entries', 'integer', 'min:1'],
            // Diploma fields
            'items.*.diploma_params' => ['sometimes', 'array'],
            'items.*.diploma_params.diplomas' => ['sometimes', 'array'],
            'items.*.diploma_params.diplomas.qty' => ['sometimes', 'integer', 'min:0'],
            'items.*.diploma_params.supplements' => ['sometimes', 'array'],
            'items.*.diploma_params.supplements.*.type' => ['sometimes', 'string'],
            'items.*.diploma_params.supplements.*.label' => ['sometimes', 'string'],
            'items.*.diploma_params.supplements.*.qty' => ['sometimes', 'integer', 'min:0'],
            'items.*.diploma_params.supplements.*.blocks' => ['sometimes', 'array'],
            'items.*.diploma_params.supplements.*.blocks.*.sheets' => ['sometimes', 'integer', 'min:0'],
            'items.*.diploma_params.supplements.*.blocks.*.mode' => ['sometimes', 'string', 'in:4+4,4+0,0+0'],
            'items.*.diploma_params.academic_records' => ['sometimes', 'array'],
            'items.*.diploma_params.academic_records.qty' => ['sometimes', 'integer', 'min:0'],
            'items.*.diploma_params.copies' => ['sometimes', 'array'],
            'items.*.diploma_params.copies.diploma_copies' => ['sometimes', 'array'],
            'items.*.diploma_params.copies.diploma_copies.qty' => ['sometimes', 'integer', 'min:0'],
            'items.*.diploma_params.copies.diploma_copies.mode' => ['sometimes', 'string', 'in:1+0'],
            'items.*.diploma_params.copies.supplement_copies' => ['sometimes', 'array'],
            'items.*.diploma_params.copies.supplement_copies.qty' => ['sometimes', 'integer', 'min:0'],
            'items.*.diploma_params.copies.supplement_copies.blocks' => ['sometimes', 'array'],
            'items.*.diploma_params.copies.supplement_copies.blocks.*.sheets' => ['sometimes', 'integer', 'min:0'],
            'items.*.diploma_params.copies.supplement_copies.blocks.*.mode' => ['sometimes', 'string', 'in:1+0,1+1'],
        ];
    }
}
