<?php

namespace App\DTOs\Product;

use App\DTOs\BaseDTO;

/**
 * DTO for Product Image Response
 */
class ProductImageDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly string $path,
        public readonly int $position,
        public readonly bool $isPrimary,
        public readonly string $createdAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->productId,
            'path' => $this->path,
            'position' => $this->position,
            'isPrimary' => $this->isPrimary,
            'createdAt' => $this->createdAt,
        ];
    }
}
