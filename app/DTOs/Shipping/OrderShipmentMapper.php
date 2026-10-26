<?php

namespace App\DTOs\Shipping;

use App\DTOs\BaseMapper;
use App\Models\OrderShipment;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting OrderShipment model to OrderShipmentDTO
 */
class OrderShipmentMapper extends BaseMapper
{
    /**
     * Convert OrderShipment model to DTO
     */
    public function toDTO(Model $model): OrderShipmentDTO
    {
        if (!$model instanceof OrderShipment) {
            throw new \InvalidArgumentException('Model must be instance of OrderShipment');
        }

        // Map sell data if available
        $sell = null;
        try {
            if ($model->sell) {
                $sell = [
                    'id' => $model->sell->id,
                    'invoiceNo' => $model->sell->invoice_no,
                    'customerName' => $model->sell->customer_name,
                    'customerEmail' => $model->sell->customer_email,
                    'customerPhone' => $model->sell->shipping_phone,
                    'customerAddress' => implode(', ', array_filter([
                        $model->sell->shipping_address_line1, $model->sell->shipping_address_line2,
                        $model->sell->shipping_city, $model->sell->shipping_state, $model->sell->shipping_postal_code,
                    ])),
                    'area' => implode(', ', array_filter([$model->sell->shipping_city, $model->sell->shipping_state])),
                    'source' => $model->sell->source,
                    'paymentStatus' => $model->sell->payment_status,
                    'dueAmount' => (float) $model->sell->due_amount,
                    'method' => $model->sell->method,
                    'amount' => (float) $model->sell->amount,
                    'status' => $model->sell->status,
                    'shippedAt' => $model->sell->shipped_at ? $this->formatTimestamp($model->sell->shipped_at) : null,
                ];
            }
        } catch (\Exception $e) {
            // Silently fail if sell data can't be loaded
            $sell = null;
        }

        return new OrderShipmentDTO(
            id: $model->id,
            companyId: $model->company_id,
            sellId: $model->sell_id,
            trackingNumber: $model->tracking_number,
            carrier: $model->carrier,
            shippingMethod: $model->shipping_method,
            status: $model->status,
            shippedAt: $model->shipped_at ? $this->formatTimestamp($model->shipped_at) : null,
            estimatedDelivery: $model->estimated_delivery ? $this->formatTimestamp($model->estimated_delivery) : null,
            deliveredAt: $model->delivered_at ? $this->formatTimestamp($model->delivered_at) : null,
            shippingCost: (float) $model->shipping_cost,
            codAmount: (float) $model->cod_amount,
            codCollected: $this->isCodCollected($model),
            codCollectedAmount: $model->cod_collected_amount !== null ? (float) $model->cod_collected_amount : null,
            codCollectedAt: $model->cod_collected_at ? $this->formatTimestamp($model->cod_collected_at) : null,
            codState: self::codState($model),
            rider: $model->rider,
            package: $model->package,
            deliveryPayer: $model->delivery_payer ?: 'customer',
            deliverySpeed: $model->delivery_speed,
            pickupDate: $model->pickup_date ? $model->pickup_date->format('Y-m-d') : null,
            weight: $model->weight ? (float) $model->weight : null,
            dimensions: $model->dimensions,
            notes: $model->notes,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            trackingHistory: $model->relationLoaded('trackingHistory') ? $this->formatTrackingHistory($model->trackingHistory) : null,
            sell: $sell,
        );
    }

    private function isCodCollected(OrderShipment $s): bool
    {
        if ($s->cod_collected) {
            return true;
        }
        // Legacy delivered COD rows whose order balance was already settled
        return $s->status === 'delivered' && self::isCod($s) && $s->sell && (float) $s->sell->due_amount <= 0;
    }

    public static function isCod(OrderShipment $s): bool
    {
        return (float) $s->cod_amount > 0 || ($s->sell && strcasecmp((string) $s->sell->method, 'COD') === 0);
    }

    /** prepaid | to_collect | collected | delivered_record_payment | returning */
    public static function codState(OrderShipment $s): string
    {
        if (!self::isCod($s)) {
            return 'prepaid';
        }
        if (in_array($s->status, ['failed', 'returned'], true)) {
            return 'returning';
        }
        if ($s->cod_collected || ($s->status === 'delivered' && $s->sell && (float) $s->sell->due_amount <= 0)) {
            return 'collected';
        }
        return $s->status === 'delivered' ? 'delivered_record_payment' : 'to_collect';
    }

    /**
     * Format tracking history
     */
    private function formatTrackingHistory($history): ?array
    {
        if ($history === null || $history->isEmpty()) {
            return null;
        }

        $historyMapper = new ShipmentTrackingHistoryMapper();
        return $historyMapper->toArrayCollection($history);
    }
}
