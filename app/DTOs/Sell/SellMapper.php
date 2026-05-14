<?php

namespace App\DTOs\Sell;

use App\DTOs\BaseMapper;
use App\Models\Sell;

class SellMapper extends BaseMapper
{
    public function toDTO($model): SellDTO
    {
        if (!$model instanceof Sell) {
            throw new \InvalidArgumentException('Model must be instance of Sell');
        }

        // Map items if loaded
        $items = null;
        if ($model->relationLoaded('items') && $model->items) {
            $itemsCollection = $model->items;
            if ($itemsCollection && (is_array($itemsCollection) || ($itemsCollection instanceof \Illuminate\Database\Eloquent\Collection && $itemsCollection->count() > 0))) {
                $items = $this->formatItemsCollection($itemsCollection);
            }
        }

        // Map shipments if loaded
        $shipments = null;
        if ($model->relationLoaded('shipments') && $model->shipments->count() > 0) {
            $shipmentsArray = $model->shipments->toArray();
            $shipments = array_map(function ($shipment) {
                $shipment = is_array($shipment) ? (object) $shipment : $shipment;
                return [
                    'id' => $shipment->id,
                    'trackingNumber' => $shipment->tracking_number,
                    'carrier' => $shipment->carrier,
                    'shippingMethod' => $shipment->shipping_method,
                    'status' => $shipment->status,
                    'shippedAt' => $this->formatTimestamp($shipment->shipped_at),
                    'deliveredAt' => $this->formatTimestamp($shipment->delivered_at),
                ];
            }, $shipmentsArray);
        }

        // Map customer if loaded
        $customer = null;
        if ($model->relationLoaded('customer') && $model->customer) {
            $customer = [
                'id' => $model->customer->id,
                'name' => $model->customer->name,
                'email' => $model->customer->email,
                'phone' => $model->customer->phone,
            ];
        }

        // Map shipping address if loaded
        $shippingAddress = null;
        if ($model->relationLoaded('shippingAddress') && $model->shippingAddress) {
            $shippingAddress = [
                'id' => $model->shippingAddress->id,
                'fullName' => $model->shippingAddress->full_name,
                'addressLine1' => $model->shippingAddress->address_line1,
                'addressLine2' => $model->shippingAddress->address_line2,
                'city' => $model->shippingAddress->city,
                'state' => $model->shippingAddress->state,
                'postalCode' => $model->shippingAddress->postal_code,
                'country' => $model->shippingAddress->country,
                'type' => $model->shippingAddress->type,
            ];
        }

        // Resolve shipping method name: prefer relationship, fall back to raw string
        $shippingMethodName = null;
        if ($model->relationLoaded('shippingMethodModel') && $model->shippingMethodModel) {
            $shippingMethodName = $model->shippingMethodModel->name;
        } elseif ($model->shipping_method) {
            $shippingMethodName = $model->shipping_method;
        }

        return new SellDTO(
            id: $model->id,
            companyId: $model->company_id,
            invoiceNo: $model->invoice_no,
            orderTime: $this->formatTimestamp($model->order_time),
            customerId: $model->customer_id,
            customer: $customer,
            customerName: $model->customer_name,
            shippingAddressId: $model->shipping_address_id,
            shippingAddress: $shippingAddress,
            shippingFullName: $model->shipping_full_name,
            shippingPhone: $model->shipping_phone,
            shippingEmail: $model->shipping_email,
            shippingAddressLine1: $model->shipping_address_line1,
            shippingAddressLine2: $model->shipping_address_line2,
            shippingCity: $model->shipping_city,
            shippingState: $model->shipping_state,
            shippingPostalCode: $model->shipping_postal_code,
            shippingCountry: $model->shipping_country,
            shippingAddressType: $model->shipping_address_type,
            method: $model->method,
            amount: $model->amount,
            shippingCost: $model->shipping_cost,
            shippingMethod: $model->shipping_method,
            shippingMethodName: $shippingMethodName,
            couponId: $model->coupon_id,
            couponCode: $model->coupon_code,
            discount: $model->discount,
            status: $model->status,
            stockDeducted: $model->stock_deducted,
            paymentStatus: $model->payment_status,
            fulfillmentStatus: $model->fulfillment_status,
            trackingNumber: $model->tracking_number,
            carrier: $model->carrier,
            shippedAt: $this->formatTimestamp($model->shipped_at),
            deliveredAt: $this->formatTimestamp($model->delivered_at),
            totalCost: $model->total_cost,
            grossProfit: $model->gross_profit,
            notes: $model->notes,
            items: $items,
            shipments: $shipments,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            shippingDepositAmount: $model->shipping_deposit_amount !== null ? (float) $model->shipping_deposit_amount : null,
            shippingDepositTransactionId: $model->shipping_deposit_transaction_id,
            paymentTransactionId: $model->payment_transaction_id,
        );
    }

    private function formatItemsCollection($items): array
    {
        // Handle both array and collection input
        if (is_array($items)) {
            $itemsArray = $items;
        } elseif ($items instanceof \Illuminate\Database\Eloquent\Collection) {
            $itemsArray = $items->toArray();
        } else {
            return [];
        }

        return array_map(function ($item) {
            // Convert array to object if needed
            $item = is_array($item) ? (object) $item : $item;

            return [
                'id' => $item->id,
                'productId' => $item->product_id,
                'variantId' => $item->variant_id,
                'inventoryId' => $item->inventory_id,
                'productName' => $item->product_name,
                'variantName' => $item->variant_name,
                'quantity' => $item->quantity,
                'unitPrice' => $item->unit_price,
                'totalPrice' => $item->total_price,
                'unitCost' => $item->unit_cost,
                'totalCost' => $item->total_cost,
            ];
        }, $itemsArray);
    }
}
