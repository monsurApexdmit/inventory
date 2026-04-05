<?php

namespace App\DTOs\User;

use App\DTOs\BaseDTO;

/**
 * DTO for User Response
 * Represents a user in the system (legacy auth)
 */
class UserDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        public readonly ?int $roleId,
        public readonly ?string $address,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?array $role = null,
    ) {}

    /**
     * Convert DTO to array for API response
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'roleId' => $this->roleId,
            'address' => $this->address,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'role' => $this->role,
        ];
    }
}
