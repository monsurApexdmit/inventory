<?php

namespace App\DTOs\Expense;

use App\DTOs\BaseDTO;

/**
 * DTO for Expense Category Response
 */
class ExpenseCategoryDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'name' => $this->name,
            'description' => $this->description,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
