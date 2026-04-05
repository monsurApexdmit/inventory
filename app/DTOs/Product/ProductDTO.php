<?php

namespace App\DTOs\Product;

use App\DTOs\BaseDTO;

/**
 * DTO for Product Response
 */
class ProductDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly ?int $categoryId,
        public readonly ?int $vendorId,
        public readonly ?int $locationId,
        public readonly string $name,
        public readonly ?string $description,
        public readonly float $price,
        public readonly float $salePrice,
        public readonly float $costPrice,
        public readonly int $stock,
        public readonly ?string $sku,
        public readonly ?string $barcode,
        public readonly bool $published,
        public readonly ?string $receiptNumber,
        public readonly ?string $image,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?array $variants = null,
        public readonly ?array $images = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'categoryId' => $this->categoryId,
            'vendorId' => $this->vendorId,
            'locationId' => $this->locationId,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'salePrice' => $this->salePrice,
            'costPrice' => $this->costPrice,
            'stock' => $this->stock,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'published' => $this->published,
            'receiptNumber' => $this->receiptNumber,
            'image' => $this->image,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'variants' => $this->variants,
            'images' => $this->images,
        ];
    }
}
