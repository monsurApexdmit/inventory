<?php

namespace App\DTOs\Product;

use App\DTOs\BaseDTO;

/**
 * DTO for Product Variant Response
 */
class ProductVariantDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly string $name,
        public readonly ?string $attributes,
        public readonly float $price,
        public readonly float $salePrice,
        public readonly float $costPrice,
        public readonly ?float $offerPrice = null,
        public readonly ?string $offerType = null,
        public readonly int $stock = 0,
        public readonly int $reorderPoint = 0,
        public readonly ?string $sku,
        public readonly ?string $barcode,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?array $inventory = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->productId,
            'name' => $this->name,
            'attributes' => $this->attributes,
            'price' => $this->price,
            'salePrice' => $this->salePrice,
            'costPrice' => $this->costPrice,
            'offerPrice' => $this->offerPrice,
            'offerType' => $this->offerType,
            'stock' => $this->stock,
            'reorderPoint' => $this->reorderPoint,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'inventory' => $this->inventory,
        ];
    }
}
