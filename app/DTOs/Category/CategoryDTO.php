<?php

namespace App\DTOs\Category;

use App\DTOs\BaseDTO;

/**
 * DTO for Category Response
 */
class CategoryDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly string $categoryName,
        public readonly ?int $parentId,
        public readonly bool $status,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?array $parent = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'categoryName' => $this->categoryName,
            'parentId' => $this->parentId,
            'status' => $this->status,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'parent' => $this->parent,
        ];
    }
}
