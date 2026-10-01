<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveParameterGroupRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'max:255'],
            'ui_type'               => ['required', 'in:radio,checkbox'],
            'ui_style'              => ['in:chips,tiles_large,tiles_small,dropdown'],
            'is_required'           => ['boolean'],
            'sort_order'            => ['integer', 'min:0'],
            'depends_on'            => ['nullable', 'array'],
            'depends_on.group_id'   => ['required_with:depends_on', 'integer'],
            'depends_on.option_ids' => ['required_with:depends_on', 'array'],
        ];
    }
}
