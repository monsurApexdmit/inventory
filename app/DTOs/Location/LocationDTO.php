<?php

namespace App\DTOs\Location;

use App\DTOs\BaseDTO;

/**
 * DTO for Location Response
 */
class LocationDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly string $name,
        public readonly ?string $address,
        public readonly ?string $contactPerson,
        public readonly bool $isDefault,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'name' => $this->name,
            'address' => $this->address,
            'contactPerson' => $this->contactPerson,
            'isDefault' => $this->isDefault,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
