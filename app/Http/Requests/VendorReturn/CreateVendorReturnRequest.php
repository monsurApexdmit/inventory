<?php

namespace App\Http\Requests\VendorReturn;

use Illuminate\Foundation\Http\FormRequest;

class CreateVendorReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendorId' => 'required|integer|min:1',
            'vendorName' => 'nullable|string|max:255',
            'purchaseOrderId' => 'nullable|integer|min:1',
            'locationId' => 'nullable|integer|min:1',
            'totalAmount' => 'nullable|numeric|min:0',
            'creditType' => 'required|string|in:note,refund,replacement,credit_note',
            'returnNumber' => 'nullable|string|max:100|unique:vendor_returns,return_number',
            'returnDate' => 'nullable|date|before_or_equal:today',
            'trackingNo' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:2000',
            'notes' => 'nullable|string',
            'createdBy' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.productId' => 'required|integer|min:1',
            'items.*.productName' => 'nullable|string|max:255',
            'items.*.variantId' => 'nullable|integer|min:1',
            'items.*.variantName' => 'nullable|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unitPrice' => 'nullable|numeric|min:0',
            'items.*.totalPrice' => 'nullable|numeric|min:0',
            'items.*.unitCost' => 'nullable|numeric|min:0',
            'items.*.reason' => 'required|string|max:255',
        ];

    }
}
