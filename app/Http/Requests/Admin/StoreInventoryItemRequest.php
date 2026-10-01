<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The zeroable money/qty columns are NOT NULL DEFAULT 0 in the DB, but the
     * edit modal sends null for a blanked (or zero-loaded) field. Coalesce
     * only keys the form actually sent — inventing an absent one here would,
     * for current_quantity, zero real stock on every edit.
     */
    protected function prepareForValidation(): void
    {
        foreach (['avg_cost', 'refill_cost', 'current_quantity', 'empty_quantity'] as $key) {
            if ($this->has($key) && $this->input($key) === null) {
                $this->merge([$key => 0]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'inventory_category_id' => ['required', 'exists:inventory_categories,id'],
            'subcategory'           => ['nullable', 'string', 'max:100'],
            'name'                  => ['required', 'string', 'max:255'],
            'unit'                  => ['required', 'string', 'max:50'],
            'min_quantity'          => ['required', 'numeric', 'min:0'],
            'avg_cost'              => ['nullable', 'numeric', 'min:0'],
            'refill_cost'           => ['nullable', 'numeric', 'min:0'],
            'current_quantity'      => ['nullable', 'numeric', 'min:0'],
            'empty_quantity'        => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
