<?php

namespace App\Http\Requests\StockAdjustment;

use Illuminate\Foundation\Http\FormRequest;

class CreateAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Accept camelCase from the frontend, normalise to snake_case.
        $this->merge([
            'product_id'  => $this->input('productId')  ?? $this->input('product_id'),
            'variant_id'  => $this->input('variantId')  ?? $this->input('variant_id'),
            'location_id' => $this->input('locationId') ?? $this->input('location_id'),
        ]);
    }

    public function rules(): array
    {
        return [
            'product_id'  => 'required|integer|min:1|exists:products,id',
            'variant_id'  => 'nullable|integer|min:1|exists:product_variants,id',
            'location_id' => 'required|integer|min:1|exists:locations,id',
            'type'        => 'required|string|in:increase,decrease,set',
            'quantity'    => 'required|integer|min:0',
            'reason'      => 'required|string|max:1000',
        ];
    }
}
