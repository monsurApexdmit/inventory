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
        ];
    }
}
