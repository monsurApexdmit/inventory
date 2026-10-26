<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;

class CreateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categoryName' => 'required|string|max:100',
            'parentId' => 'nullable|integer|min:1',
            'status' => 'nullable|boolean',
            'color' => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'description' => 'nullable|string|max:5000',
            'image' => ['nullable', function ($attr, $value, $fail) {
                if ($value instanceof \Illuminate\Http\UploadedFile) {
                    if (!$value->isValid() || !in_array(strtolower($value->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) || $value->getSize() > 5 * 1024 * 1024) {
                        $fail('The image must be a jpg, png, webp or gif file up to 5MB.');
                    }
                } elseif (!is_string($value) || strlen($value) > 255) {
                    $fail('The image must be a file or a URL up to 255 characters.');
                }
            }],
        ];
    }
}
