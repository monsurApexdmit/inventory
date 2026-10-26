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
            'optionType' => 'sometimes|nullable|in:text,dropdown,radio,checkbox,color,size,button,swatch',
            'values' => ['sometimes', 'nullable', function ($attr, $value, $fail) {
                if (is_string($value)) {
                    return;
                }
                if (!is_array($value)) {
                    $fail('The values must be a string or an array.');
                    return;
                }
                foreach ($value as $v) {
                    if (is_string($v)) {
                        continue;
                    }
                    if (!is_array($v) || !isset($v['label']) || !is_string($v['label']) || trim($v['label']) === '') {
                        $fail('Each value must be a string or an object with a label.');
                        return;
                    }
                    $color = $v['color'] ?? $v['c'] ?? null;
                    if ($color !== null && $color !== '' && !(is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color))) {
                        $fail('Swatch colours must be in #RRGGBB format.');
                        return;
                    }
                }
            }],
            'description' => 'sometimes|nullable|string',
            'isRequired' => 'sometimes|nullable|boolean',
            'status' => 'sometimes|nullable|boolean',
            'sortOrder' => 'sometimes|nullable|integer|min:0',
        ];
    }
}
