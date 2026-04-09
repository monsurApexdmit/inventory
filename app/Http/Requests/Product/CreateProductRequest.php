<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class CreateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        // Accept both camelCase and snake_case
        $fieldMap = [
            'categoryId' => 'category_id',
            'vendorId' => 'vendor_id',
            'locationId' => 'location_id',
            'salePrice' => 'sale_price',
            'costPrice' => 'cost_price',
            'profitMargin' => 'profit_margin',
            'marginType' => 'margin_type',
            'receiptNumber' => 'receipt_number',
        ];

        // Pass through fields that don't need conversion
        $passthroughFields = ['stock', 'price', 'sku', 'barcode'];
        foreach ($passthroughFields as $field) {
            if ($this->has($field) && !isset($merged[$field])) {
                $merged[$field] = $this->input($field);
            }
        }

        foreach ($fieldMap as $camel => $snake) {
            // Check camelCase first, then snake_case
            if ($this->has($camel)) {
                $merged[$snake] = $this->input($camel);
            } elseif ($this->has($snake)) {
                $merged[$snake] = $this->input($snake);
            }
        }

        // Convert string "true"/"false" to boolean for published
        if ($this->has('published')) {
            $published = $this->input('published');
            $merged['published'] = filter_var($published, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        // Parse JSON strings to arrays if they're strings
        if ($this->has('variants') && is_string($this->input('variants'))) {
            $merged['variants'] = json_decode($this->input('variants'), true) ?? [];
        }

        if ($this->has('attributes') && is_string($this->input('attributes'))) {
            $merged['attributes'] = json_decode($this->input('attributes'), true) ?? [];
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|integer|min:1',
            'vendor_id' => 'nullable|integer|min:1',
            'location_id' => 'nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'profit_margin' => 'nullable|numeric|min:0',
            'margin_type' => 'nullable|in:percentage,flat',
            'stock' => 'nullable|integer|min:0',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'published' => 'nullable|boolean',
            'receipt_number' => 'nullable|string|max:100',
            'image' => 'nullable|array',
            'image.*' => 'nullable|file|mimes:jpg,jpeg,png,webp,gif|max:5120',
            'variants' => 'nullable|array',
            'variants.*.name' => 'required_with:variants|string',
            'variants.*.price' => 'nullable|numeric|min:0',
            'variants.*.sale_price' => 'nullable|numeric|min:0',
            'variants.*.cost_price' => 'nullable|numeric|min:0',
            'variants.*.profit_margin' => 'nullable|numeric|min:0',
            'variants.*.margin_type' => 'nullable|in:percentage,flat',
            'variants.*.stock' => 'nullable|integer|min:0',
            'variants.*.sku' => 'nullable|string|max:100',
            'variants.*.barcode' => 'nullable|string|max:100',
            'variants.*.attributes' => 'nullable',
            'attributes' => 'nullable|array',
            'attributes.*' => 'integer|min:1',
        ];
    }
}
