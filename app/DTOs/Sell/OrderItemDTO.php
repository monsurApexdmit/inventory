<?php

namespace App\DTOs\Sell;

use App\DTOs\BaseDTO;

class OrderItemDTO extends BaseDTO
{
    /**
     * @readonly
     */
    public function __construct(
        public int $id,
        public int $productId,
        public ?int $variantId,
        public ?int $inventoryId,
        public string $productName,
        public ?string $variantName,
        public int $quantity,
        public float $unitPrice,
        public float $totalPrice,
        public float $unitCost,
        public float $totalCost,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->productId,
            'variantId' => $this->variantId,
            'inventoryId' => $this->inventoryId,
            'productName' => $this->productName,
            'variantName' => $this->variantName,
            'quantity' => $this->quantity,
            'unitPrice' => $this->unitPrice,
            'totalPrice' => $this->totalPrice,
            'unitCost' => $this->unitCost,
            'totalCost' => $this->totalCost,
        ];
    }
}
