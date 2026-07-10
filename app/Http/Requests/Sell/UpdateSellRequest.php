<?php

namespace App\Http\Requests\Sell;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSellRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoiceNo' => 'sometimes|nullable|string|max:100',
            'orderTime' => 'sometimes|nullable|date_format:Y-m-d\TH:i:s\Z|date_format:Y-m-d H:i:s',
            'customerId' => 'sometimes|nullable|integer|exists:customers,id',
            'customerName' => 'sometimes|nullable|string|max:255',
            'method' => 'sometimes|nullable|string|in:Cash,Card,Online',
            'amount' => 'sometimes|nullable|numeric|min:0',
            'shippingCost' => 'sometimes|nullable|numeric|min:0',
            'discount' => 'sometimes|nullable|numeric|min:0',
            'status' => 'sometimes|nullable|string|in:Pending,Processing,Delivered',
            'notes' => 'sometimes|nullable|string',
        ];
    }
}
