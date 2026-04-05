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
            'vendorName' => 'required|string|max:255',
            'totalAmount' => 'nullable|numeric|min:0',
            'creditType' => 'required|string|in:refund,credit_note,replacement',
            'returnNumber' => 'nullable|string|max:100',
            'returnDate' => 'nullable|date',
            'notes' => 'nullable|string',
            'createdBy' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.productId' => 'nullable|integer|min:1',
            'items.*.productName' => 'required|string|max:255',
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
