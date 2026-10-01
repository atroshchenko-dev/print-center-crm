<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a stock receipt (Прибуткова накладна).
 * Extracted from InventoryController::receipt() inline validation.
 */
class StoreInventoryReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission:inventory middleware handles authorization
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'inventory_item_id'  => ['required', 'exists:inventory_items,id'],
            'packs_quantity'     => ['required', 'numeric', 'min:0.01'],
            'units_per_pack'     => ['required', 'numeric', 'min:1'],
            'price_per_pack'     => ['required', 'numeric', 'min:0'],
            'notes'              => ['nullable', 'string', 'max:500'],
            'auto_update_markup' => ['boolean'],
        ];
    }
}
