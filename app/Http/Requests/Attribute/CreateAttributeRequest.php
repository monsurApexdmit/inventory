<?php

namespace App\Http\Requests\Attribute;

use Illuminate\Foundation\Http\FormRequest;

class CreateAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'displayName' => 'required|string|max:150',
            'optionType' => 'required|in:text,dropdown,radio,checkbox,color,size',
            'values' => 'nullable|string',
            'description' => 'nullable|string',
            'isRequired' => 'nullable|boolean',
            'status' => 'nullable|boolean',
            'sortOrder' => 'nullable|integer|min:0',
        ];
    }
}
