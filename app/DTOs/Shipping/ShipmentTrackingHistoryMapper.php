<?php

namespace App\DTOs\Shipping;

use App\DTOs\BaseMapper;
use App\Models\ShipmentTrackingHistory;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting ShipmentTrackingHistory model to ShipmentTrackingHistoryDTO
 */
class ShipmentTrackingHistoryMapper extends BaseMapper
{
    /**
     * Convert ShipmentTrackingHistory model to DTO
     */
    public function toDTO(Model $model): ShipmentTrackingHistoryDTO
    {
        if (!$model instanceof ShipmentTrackingHistory) {
            throw new \InvalidArgumentException('Model must be instance of ShipmentTrackingHistory');
        }

        return new ShipmentTrackingHistoryDTO(
            id: $model->id,
            shipmentId: $model->shipment_id,
            status: $model->status,
            location: $model->location,
            description: $model->description,
            eventTime: $this->formatTimestamp($model->event_time),
            createdAt: $this->formatTimestamp($model->created_at),
        );
    }
}
