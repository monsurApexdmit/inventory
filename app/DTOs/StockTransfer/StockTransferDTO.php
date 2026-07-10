<?php

namespace App\DTOs\StockTransfer;

use App\DTOs\BaseDTO;

/**
 * DTO for Stock Transfer Response
 */
class StockTransferDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly int $productId,
        public readonly ?int $variantId,
        public readonly int $fromLocationId,
        public readonly int $toLocationId,
        public readonly int $quantity,
        public readonly string $status,
        public readonly ?string $notes,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?array $product = null,
        public readonly ?array $variant = null,
        public readonly ?array $fromLocation = null,
        public readonly ?array $toLocation = null,
        public readonly ?array $company = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'productId' => $this->productId,
            'variantId' => $this->variantId,
            'fromLocationId' => $this->fromLocationId,
            'toLocationId' => $this->toLocationId,
            'quantity' => $this->quantity,
            'status' => $this->status,
            'notes' => $this->notes,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'product' => $this->product,
            'variant' => $this->variant,
            'fromLocation' => $this->fromLocation,
            'toLocation' => $this->toLocation,
            'company' => $this->company,
        ];
    }
}
