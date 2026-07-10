<?php

namespace App\DTOs\VendorReturn;

use App\DTOs\BaseDTO;

/**
 * DTO for Vendor Return Item Response
 */
class VendorReturnItemDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $returnId,
        public readonly ?int $productId,
        public readonly string $productName,
        public readonly ?int $variantId,
        public readonly ?string $variantName,
        public readonly int $quantity,
        public readonly float $unitPrice,
        public readonly float $totalPrice,
        public readonly float $unitCost,
        public readonly string $reason,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'returnId' => $this->returnId,
            'productId' => $this->productId,
            'productName' => $this->productName,
            'variantId' => $this->variantId,
            'variantName' => $this->variantName,
            'quantity' => $this->quantity,
            'unitPrice' => $this->unitPrice,
            'totalPrice' => $this->totalPrice,
            'unitCost' => $this->unitCost,
            'reason' => $this->reason,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
