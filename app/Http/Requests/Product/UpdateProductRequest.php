<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
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
            'unitId' => 'unit_id',
            'brandId' => 'brand_id',
            'vendorId' => 'vendor_id',
            'locationId' => 'location_id',
            'salePrice' => 'sale_price',
            'offerPrice' => 'offer_price',
            'offerType' => 'offer_type',
            'offerEndsAt' => 'offer_ends_at',
            'wholesalePrice' => 'wholesale_price',
            'minWholesaleQty' => 'min_wholesale_qty',
            'costPrice' => 'cost_price',
            'profitMargin' => 'profit_margin',
            'marginType' => 'margin_type',
            'receiptNumber' => 'receipt_number',
            'availableInPos' => 'available_in_pos',
            'visibleOnline' => 'visible_online',
        ];

        // Pass through fields that don't need conversion
        $passthroughFields = ['name', 'description', 'stock', 'price', 'sku', 'barcode', 'barcode_type'];
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

        foreach (['available_in_pos', 'visible_online'] as $boolField) {
            if (array_key_exists($boolField, $merged)) {
                $merged[$boolField] = filter_var($merged[$boolField], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
        }
        if ($this->has('tag')) {
            $t = trim((string) $this->input('tag'));
            $merged['tag'] = $t === '' ? null : $t;
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

        if ($this->has('bundleItems') && is_string($this->input('bundleItems'))) {
            $merged['bundleItems'] = json_decode($this->input('bundleItems'), true) ?? [];
        }

        foreach (['isBundle', 'bundlePriceOverride', 'reorderPoint', 'trackingType'] as $field) {
            if ($this->has($field) && !isset($merged[$field])) {
                $merged[$field] = $this->input($field);
            }
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function messages(): array
    {
        return [
            'image.max' => 'You can upload at most 6 images per product.',
            'image.*.mimes' => 'Images must be JPG, PNG or WEBP.',
            'image.*.max' => 'Each image must be 5 MB or smaller.',
        ];
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string',
            'category_id' => 'sometimes|nullable|integer|min:1',
            'unit_id' => 'sometimes|nullable|integer|min:1',
            'brand_id' => 'sometimes|nullable|integer|min:1',
            'vendor_id' => 'sometimes|nullable|integer|min:1',
            'location_id' => 'sometimes|nullable|integer|min:1',
            'price' => 'sometimes|nullable|numeric|min:0',
            'sale_price' => 'sometimes|nullable|numeric|min:0',
            'offer_price' => 'sometimes|nullable|numeric|min:0',
            'offer_type' => 'sometimes|nullable|in:percentage,flat',
            'offer_ends_at' => 'sometimes|nullable|date',
            'wholesale_price' => 'sometimes|nullable|numeric|min:0',
            'min_wholesale_qty' => 'sometimes|nullable|integer|min:1',
            'cost_price' => 'sometimes|nullable|numeric|min:0',
            'profit_margin' => 'sometimes|nullable|numeric|min:0',
            'margin_type' => 'sometimes|nullable|in:percentage,flat',
            'stock' => 'sometimes|nullable|integer|min:0',
            'sku' => 'sometimes|nullable|string|max:100',
            'barcode' => 'sometimes|nullable|string|max:100',
            'barcode_type' => 'sometimes|nullable|string|max:20',
            'published' => 'sometimes|nullable|boolean',
            'is_hot_deal' => 'sometimes|nullable',
            'is_best_seller' => 'sometimes|nullable',
            'is_featured' => 'sometimes|nullable',
            'available_in_pos' => 'sometimes|nullable|boolean',
            'visible_online' => 'sometimes|nullable|boolean',
            'tag' => 'sometimes|nullable|string|max:50',
            'deal_label' => 'sometimes|nullable|string|max:50',
            'receipt_number' => 'sometimes|nullable|string|max:100',
            'image' => 'sometimes|nullable|array|max:6',
            'image.*' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'variants' => 'sometimes|nullable|array',
            'variants.*.name' => 'required_with:variants|string',
            'variants.*.price' => 'nullable|numeric|min:0',
            'variants.*.sale_price' => 'nullable|numeric|min:0',
            'variants.*.cost_price' => 'nullable|numeric|min:0',
            'variants.*.profit_margin' => 'nullable|numeric|min:0',
            'variants.*.margin_type' => 'nullable|in:percentage,flat',
            'variants.*.stock' => 'nullable|integer|min:0',
            'variants.*.offer_price' => 'nullable|numeric|min:0',
            'variants.*.offer_type' => 'nullable|in:percentage,flat',
            'variants.*.wholesale_price' => 'nullable|numeric|min:0',
            'variants.*.min_wholesale_qty' => 'nullable|integer|min:1',
            'variants.*.sku' => 'nullable|string|max:100',
            'variants.*.barcode' => 'nullable|string|max:100',
            'variants.*.attributes' => 'nullable',
            'attributes' => 'sometimes|nullable|array',
            'attributes.*' => 'integer|min:1',
            'reorderPoint' => 'sometimes|nullable|integer|min:0',
            'trackingType' => 'sometimes|nullable|in:none,serial,batch',
            'isBundle' => 'sometimes|nullable',
            'bundlePriceOverride' => 'sometimes|nullable|numeric|min:0',
            'bundleItems' => 'sometimes|nullable|array',
            'bundleItems.*.productId' => 'required_with:bundleItems|integer|min:1',
            'bundleItems.*.variantId' => 'nullable|integer|min:1',
            'bundleItems.*.quantity' => 'nullable|integer|min:1',
            'keep_images' => 'sometimes|nullable|array',
            'keep_images.*' => 'string',
            'delete_images' => 'sometimes|nullable|boolean',
        ];
    }
}
