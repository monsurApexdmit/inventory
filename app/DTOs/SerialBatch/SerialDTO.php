<?php

namespace App\DTOs\SerialBatch;

use App\DTOs\BaseDTO;

class SerialDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly int $productId,
        public readonly string $productName,
        public readonly ?int $variantId,
        public readonly ?string $variantName,
        public readonly ?int $locationId,
        public readonly ?string $locationName,
        public readonly string $serialNumber,
        public readonly string $status,
        public readonly ?string $purchaseOrderNumber,
        public readonly ?string $receivedDate,
        public readonly ?int $soldInSellId,
        public readonly ?string $soldDate,
        public readonly ?string $notes,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'companyId'           => $this->companyId,
            'productId'           => $this->productId,
            'productName'         => $this->productName,
            'variantId'           => $this->variantId,
            'variantName'         => $this->variantName,
            'locationId'          => $this->locationId,
            'locationName'        => $this->locationName,
            'serialNumber'        => $this->serialNumber,
            'status'              => $this->status,
            'purchaseOrderNumber' => $this->purchaseOrderNumber,
            'receivedDate'        => $this->receivedDate,
            'soldInSellId'        => $this->soldInSellId,
            'soldDate'            => $this->soldDate,
            'notes'               => $this->notes,
            'createdAt'           => $this->createdAt,
            'updatedAt'           => $this->updatedAt,
        ];
    }
}
