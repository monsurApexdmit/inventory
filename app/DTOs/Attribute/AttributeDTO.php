<?php

namespace App\DTOs\Attribute;

use App\DTOs\BaseDTO;

/**
 * DTO for Attribute Response
 */
class AttributeDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly string $name,
        public readonly string $displayName,
        public readonly string $optionType,
        public readonly ?string $values,
        public readonly ?string $description,
        public readonly bool $isRequired,
        public readonly bool $status,
        public readonly int $sortOrder,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'name' => $this->name,
            'displayName' => $this->displayName,
            'optionType' => $this->optionType,
            'values' => $this->values,
            'description' => $this->description,
            'isRequired' => $this->isRequired,
            'status' => $this->status,
            'sortOrder' => $this->sortOrder,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
