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
        public readonly ?string $color = null,
        public readonly ?float $monthlyBudget = null,
        public readonly string $type = 'Operating cost',
        public readonly bool $status = true,
        public readonly ?array $usage = null,
        public readonly bool $system = false,
    ) {}

    public function toArray(): array
    {
        $out = [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'name' => $this->name,
            'description' => $this->description,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'color' => $this->color,
            'monthlyBudget' => $this->monthlyBudget,
            'type' => $this->type,
            'status' => $this->status,
            'system' => $this->system,
        ];

        return $this->usage ? array_merge($out, $this->usage) : $out;
    }
}
