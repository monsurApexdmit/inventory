<?php

namespace App\Http\Requests\Unit;

use Illuminate\Foundation\Http\FormRequest;

class CreateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unitName' => 'required|string|max:100',
            'symbol'   => 'nullable|string|max:20',
            'type'     => 'nullable|in:Count,Weight,Volume,Length',
            'conversionQty'    => 'nullable|numeric|gt:0|max:99999999999',
            'conversionUnitId' => 'nullable|integer|min:1',
            'status'   => 'nullable|boolean',
        ];
    }
}
