<?php

namespace App\DTOs\Shipping;

use App\DTOs\BaseDTO;

/**
 * DTO for Shipment Tracking History Response
 */
class ShipmentTrackingHistoryDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $shipmentId,
        public readonly ?string $status,
        public readonly ?string $location,
        public readonly ?string $description,
        public readonly string $eventTime,
        public readonly string $createdAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shipmentId' => $this->shipmentId,
            'status' => $this->status,
            'location' => $this->location,
            'description' => $this->description,
            'eventTime' => $this->eventTime,
            'createdAt' => $this->createdAt,
        ];
    }
}
