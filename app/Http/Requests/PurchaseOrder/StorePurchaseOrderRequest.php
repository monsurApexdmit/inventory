<?php

namespace App\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendorId'      => 'required|integer',
            'locationId'    => 'nullable|integer',
            'expectedDate'  => 'nullable|date|after_or_equal:yesterday',
            'notes'         => 'nullable|string',
            'status'        => 'nullable|in:draft,sent,received',
            'discountType'  => 'nullable|in:flat,percent',
            'discountValue' => 'nullable|numeric|min:0|max:' . ($this->input('discountType') === 'percent' ? '100' : '999999999'),
            'taxRate'       => 'nullable|numeric|min:0|max:100',
            'shippingCost'  => 'nullable|numeric|min:0',
            'paidAmount'    => 'nullable|numeric|min:0',
            'paymentMethod' => 'nullable|string|max:50',
            'paymentDate'   => 'nullable|date|before_or_equal:tomorrow',
            'reference'     => 'nullable|string|max:255',
            'items'         => 'required|array|min:1',
            'items.*.productId'       => 'required|integer',
            'items.*.variantId'       => 'nullable|integer',
            'items.*.quantityOrdered' => 'required|integer|min:1',
            'items.*.unitCost'        => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'expectedDate.after_or_equal' => 'Expected delivery cannot be in the past.',
            'paymentDate.before_or_equal' => 'Payment date cannot be in the future.',
            'discountValue.max'           => 'Discount cannot be more than 100%.',
            'taxRate.max'                 => 'Tax rate must be between 0 and 100%.',
        ];
    }
}
