<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class AdjustStockProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'nullable|string|max:255',
            'items' => 'required|array|min:1|max:500',
            'items.*.productId' => 'required|integer|min:1',
            'items.*.variantId' => 'nullable|integer|min:1',
            'items.*.locationId' => 'nullable|integer|min:1',
            'items.*.delta' => 'required|integer|not_in:0',
            'items.*.reason' => 'nullable|string|max:255',
        ];
    }
}
