<?php

namespace App\DTOs\SerialBatch;

use App\DTOs\BaseDTO;

class BatchDTO extends BaseDTO
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
        public readonly string $batchNumber,
        public readonly int $quantityReceived,
        public readonly int $quantityRemaining,
        public readonly ?string $manufactureDate,
        public readonly ?string $expiryDate,
        public readonly ?string $purchaseOrderNumber,
        public readonly ?string $receivedDate,
        public readonly ?string $notes,
        public readonly bool $isExpired,
        public readonly bool $isExpiringSoon,
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
            'batchNumber'         => $this->batchNumber,
            'quantityReceived'    => $this->quantityReceived,
            'quantityRemaining'   => $this->quantityRemaining,
            'manufactureDate'     => $this->manufactureDate,
            'expiryDate'          => $this->expiryDate,
            'purchaseOrderNumber' => $this->purchaseOrderNumber,
            'receivedDate'        => $this->receivedDate,
            'notes'               => $this->notes,
            'isExpired'           => $this->isExpired,
            'isExpiringSoon'      => $this->isExpiringSoon,
            'createdAt'           => $this->createdAt,
            'updatedAt'           => $this->updatedAt,
        ];
    }
}
