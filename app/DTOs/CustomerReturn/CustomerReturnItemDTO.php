<?php

namespace App\DTOs\CustomerReturn;

use App\DTOs\BaseDTO;

/**
 * DTO for Customer Return Item Response
 */
class CustomerReturnItemDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $returnId,
        public readonly ?int $productId,
        public readonly string $productName,
        public readonly ?int $variantId,
        public readonly ?string $variantName,
        public readonly int $quantity,
        public readonly float $price,
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
            'price' => $this->price,
            'reason' => $this->reason,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
