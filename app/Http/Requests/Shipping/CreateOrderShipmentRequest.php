<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $map = [
            'sell_id' => 'sellId', 'tracking_number' => 'trackingNumber', 'shipping_method' => 'shippingMethod',
            'shipped_at' => 'shippedAt', 'estimated_delivery' => 'estimatedDelivery', 'delivered_at' => 'deliveredAt',
            'shipping_cost' => 'shippingCost', 'cod_amount' => 'codAmount', 'delivery_payer' => 'deliveryPayer',
            'delivery_speed' => 'deliverySpeed', 'pickup_date' => 'pickupDate', 'mark_shipped' => 'markShipped',
        ];
        $merge = [];
        foreach ($map as $snake => $camel) {
            if ($this->has($snake) && !$this->has($camel)) {
                $merge[$camel] = $this->input($snake);
            }
        }
        if ($merge) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'sellId' => 'required|integer|min:1',
            'trackingNumber' => 'nullable|string|max:100',
            'carrier' => 'required|string|max:100',
            'shippingMethod' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:pending,picked_up,in_transit,out_for_delivery,delivered,failed,returned',
            'shippedAt' => 'nullable|date',
            'estimatedDelivery' => 'nullable|date',
            'deliveredAt' => 'nullable|date',
            'shippingCost' => 'nullable|numeric|min:0',
            'codAmount' => 'nullable|numeric|min:0',
            'deliveryPayer' => 'nullable|string|in:customer,us',
            'deliverySpeed' => 'nullable|string|in:regular,express',
            'pickupDate' => 'nullable|date',
            'rider' => 'nullable|string|max:100',
            'package' => 'nullable|string|max:50',
            'markShipped' => 'nullable|boolean',
            'weight' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|string',
            'notes' => 'nullable|string',
        ];
    }
}
