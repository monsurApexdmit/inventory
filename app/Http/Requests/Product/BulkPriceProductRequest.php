<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class BulkPriceProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'percent' => 'required|numeric|gt:-100|lte:1000',
            'ids' => 'required_without:filter|nullable|array|min:1',
            'ids.*' => 'integer|min:1',
            'filter' => 'required_without:ids|nullable|array',
        ];
    }

    public function messages(): array
    {
        return ['percent.gt' => 'Percent must be greater than -100.'];
    }
}
