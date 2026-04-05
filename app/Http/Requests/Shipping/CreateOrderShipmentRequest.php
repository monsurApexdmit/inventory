<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sellId' => 'required|integer|min:1',
            'trackingNumber' => 'required|string|max:100',
            'carrier' => 'required|string|max:100',
            'shippingMethod' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:pending,picked_up,in_transit,out_for_delivery,delivered,failed,returned',
            'shippedAt' => 'nullable|date',
            'estimatedDelivery' => 'nullable|date',
            'deliveredAt' => 'nullable|date',
            'shippingCost' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|string',
            'notes' => 'nullable|string',
        ];
    }
}
