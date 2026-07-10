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
                    'customerPhone' => $model->sell->customer_phone,
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
            weight: $model->weight ? (float) $model->weight : null,
            dimensions: $model->dimensions,
            notes: $model->notes,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            trackingHistory: $model->relationLoaded('trackingHistory') ? $this->formatTrackingHistory($model->trackingHistory) : null,
            sell: $sell,
        );
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
