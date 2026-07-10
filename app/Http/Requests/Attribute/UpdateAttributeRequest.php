<?php

namespace App\Http\Requests\Attribute;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|nullable|string|max:100',
            'displayName' => 'sometimes|nullable|string|max:150',
            'optionType' => 'sometimes|nullable|in:text,dropdown,radio,checkbox,color,size',
            'values' => 'sometimes|nullable|string',
            'description' => 'sometimes|nullable|string',
            'isRequired' => 'sometimes|nullable|boolean',
            'status' => 'sometimes|nullable|boolean',
            'sortOrder' => 'sometimes|nullable|integer|min:0',
        ];
    }
}
