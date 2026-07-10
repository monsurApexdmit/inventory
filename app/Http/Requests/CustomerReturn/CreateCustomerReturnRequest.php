<?php

namespace App\Http\Requests\CustomerReturn;

use Illuminate\Foundation\Http\FormRequest;

class CreateCustomerReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'returnNumber' => 'nullable|string|max:100|unique:customer_returns',
            'customerId' => 'nullable|integer|min:1',
            'customerName' => 'nullable|string|max:255',
            'orderId' => 'nullable|integer|min:1',
            'orderNumber' => 'nullable|string|max:100',
            'totalAmount' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:pending,approved,rejected,completed',
            'requestDate' => 'nullable|date',
            'refundMethod' => 'required|string|in:cash,store_credit,original_payment',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.productId' => 'nullable|integer|min:1',
            'items.*.productName' => 'nullable|string|max:255',
            'items.*.variantId' => 'nullable|integer|min:1',
            'items.*.variantName' => 'nullable|string|max:255',
            'items.*.quantity' => 'nullable|integer|min:1',
            'items.*.price' => 'nullable|numeric|min:0',
            'items.*.reason' => 'required|string|max:255',
        ];
    }
}
