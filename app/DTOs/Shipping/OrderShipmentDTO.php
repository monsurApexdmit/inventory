<?php

namespace App\DTOs\Shipping;

use App\DTOs\BaseDTO;

/**
 * DTO for Order Shipment Response
 */
class OrderShipmentDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly int $sellId,
        public readonly string $trackingNumber,
        public readonly string $carrier,
        public readonly ?string $shippingMethod,
        public readonly string $status,
        public readonly ?string $shippedAt,
        public readonly ?string $estimatedDelivery,
        public readonly ?string $deliveredAt,
        public readonly float $shippingCost,
        public readonly ?float $weight,
        public readonly ?string $dimensions,
        public readonly ?string $notes,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?array $trackingHistory = null,
        public readonly ?array $sell = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'sellId' => $this->sellId,
            'trackingNumber' => $this->trackingNumber,
            'carrier' => $this->carrier,
            'shippingMethod' => $this->shippingMethod,
            'status' => $this->status,
            'shippedAt' => $this->shippedAt,
            'estimatedDelivery' => $this->estimatedDelivery,
            'deliveredAt' => $this->deliveredAt,
            'shippingCost' => $this->shippingCost,
            'weight' => $this->weight,
            'dimensions' => $this->dimensions,
            'notes' => $this->notes,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'trackingHistory' => $this->trackingHistory,
            'sell' => $this->sell,
        ];
    }
}
