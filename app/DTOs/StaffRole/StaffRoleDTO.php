<?php

namespace App\DTOs\StaffRole;

use App\DTOs\BaseDTO;

/**
 * DTO for Staff Role Response
 */
class StaffRoleDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $companyId,
        public readonly string $name,
        public readonly array $permissions,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'name' => $this->name,
            'permissions' => $this->permissions,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
