<?php

namespace App\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendorId'      => 'nullable|integer',
            'locationId'    => 'nullable|integer',
            'status'        => 'nullable|in:draft,sent,cancelled',
            'discountType'  => 'nullable|in:flat,percent',
            'discountValue' => 'nullable|numeric|min:0|max:' . ($this->input('discountType') === 'percent' ? '100' : '999999999'),
            'taxRate'       => 'nullable|numeric|min:0|max:100',
            'shippingCost'  => 'nullable|numeric|min:0',
            'expectedDate'  => 'nullable|date',
            'notes'         => 'nullable|string',
            'items'         => 'nullable|array|min:1',
            'items.*.productId'        => 'required_with:items|integer',
            'items.*.variantId'        => 'nullable|integer',
            'items.*.quantityOrdered'  => 'required_with:items|integer|min:1',
            'items.*.quantityReceived' => 'nullable|integer|min:0',
            'items.*.unitCost'         => 'required_with:items|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'discountValue.max' => 'Discount cannot be more than 100%.',
            'taxRate.max'       => 'Tax rate must be between 0 and 100%.',
        ];
    }
}
