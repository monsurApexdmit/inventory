<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categoryName' => 'sometimes|nullable|string|max:100',
            'parentId' => 'sometimes|nullable|integer|min:1',
            'status' => 'sometimes|nullable|boolean',
        ];
    }
}
