<?php

namespace App\Http\Requests\Sell;

use Illuminate\Foundation\Http\FormRequest;

class CreateSellRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoiceNo' => 'nullable|string|max:100',
            'orderTime' => 'nullable|date_format:Y-m-d\TH:i:s\Z|date_format:Y-m-d H:i:s',
            'customerId' => 'nullable|integer|exists:customers,id',
            'customerName' => 'required|string|max:255',
            'shippingAddressId' => 'nullable|integer|exists:shipping_addresses,id',
            'shippingFullName' => 'nullable|string|max:255',
            'shippingPhone' => 'nullable|string|max:50',
            'shippingEmail' => 'nullable|email|max:255',
            'shippingAddressLine1' => 'nullable|string|max:255',
            'shippingAddressLine2' => 'nullable|string|max:255',
            'shippingCity' => 'nullable|string|max:100',
            'shippingState' => 'nullable|string|max:100',
            'shippingPostalCode' => 'nullable|string|max:20',
            'shippingCountry' => 'nullable|string|max:100',
            'shippingAddressType' => 'nullable|string|max:50',
            'method' => 'nullable|string|in:Cash,Card,Online',
            'amount' => 'nullable|numeric|min:0',
            'shippingCost' => 'nullable|numeric|min:0',
            'shippingMethod' => 'nullable|string|max:100',
            'couponId' => 'nullable|integer|exists:coupons,id',
            'couponCode' => 'nullable|string|max:100',
            'discount' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:Pending,Processing,Delivered',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.productId' => 'nullable|integer',
            'items.*.variantId' => 'nullable|integer',
            'items.*.inventoryId' => 'nullable|integer',
            'items.*.productName' => 'required|string|max:255',
            'items.*.variantName' => 'nullable|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unitPrice' => 'required|numeric|min:0',
            'items.*.price' => 'nullable|numeric|min:0', // alias for unitPrice
        ];
    }

    public function messages(): array
    {
        return [
            'customerName.required' => 'Customer name is required',
            'items.*.quantity.required' => 'Item quantity is required and must be at least 1',
            'items.*.unitPrice.required' => 'Item unit price is required',
            'method.in' => 'Payment method must be one of: Cash, Card, Online',
            'status.in' => 'Status must be one of: Pending, Processing, Delivered',
        ];
    }
}
