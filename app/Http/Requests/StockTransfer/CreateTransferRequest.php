<?php

namespace App\Http\Requests\StockTransfer;

use Illuminate\Foundation\Http\FormRequest;

class CreateTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Convert camelCase to snake_case
        $this->merge([
            'product_id' => $this->input('productId') ?? $this->input('product_id'),
            'variant_id' => $this->input('variantId') ?? $this->input('variant_id'),
            'from_location_id' => $this->input('fromLocationId') ?? $this->input('from_location_id'),
            'to_location_id' => $this->input('toLocationId') ?? $this->input('to_location_id'),
        ]);
    }

    public function rules(): array
    {
        return [
            'product_id' => 'required|integer|min:1|exists:products,id',
            'variant_id' => 'nullable|integer|min:1|exists:product_variants,id',
            'from_location_id' => 'required|integer|min:1|exists:locations,id',
            'to_location_id' => 'required|integer|min:1|exists:locations,id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ];
    }
}
