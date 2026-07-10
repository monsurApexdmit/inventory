<?php

namespace App\DTOs\Team;

use App\DTOs\BaseDTO;

/**
 * DTO for Invitation Response
 */
class InvitationDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly string $email,
        public readonly ?string $fullName,
        public readonly ?int $roleId,
        public readonly string $status,
        public readonly string $invitationToken,
        public readonly string $expiresAt,
        public readonly ?string $acceptedAt,
        public readonly string $invitedAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'email' => $this->email,
            'fullName' => $this->fullName,
            'roleId' => $this->roleId,
            'status' => $this->status,
            'invitationToken' => $this->invitationToken,
            'expiresAt' => $this->expiresAt,
            'acceptedAt' => $this->acceptedAt,
            'invitedAt' => $this->invitedAt,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
