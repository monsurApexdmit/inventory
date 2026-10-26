<?php

namespace App\DTOs\Staff;

use App\DTOs\BaseDTO;

/**
 * DTO for Staff Response
 * Represents a staff member
 */
class StaffDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly ?int $userId,
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?string $contact,
        public readonly ?string $joiningDate,
        public readonly ?string $role,
        public readonly string $status,
        public readonly bool $published,
        public readonly ?string $avatar,
        public readonly float $salary,
        public readonly ?string $bankAccount,
        public readonly ?string $paymentMethod,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?array $user = null,
        public readonly ?int $staffRoleId = null,
        public readonly ?string $nid = null,
        public readonly ?string $emergencyContact = null,
        public readonly string $loginMethod = 'email',
        public readonly bool $hasPin = false,
        public readonly array $locationIds = [],
        public readonly ?string $lastActiveAt = null,
        public readonly ?string $invitedAt = null,
    ) {}

    /**
     * Convert DTO to array for API response
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'userId' => $this->userId,
            'name' => $this->name,
            'email' => $this->email,
            'contact' => $this->contact,
            'joiningDate' => $this->joiningDate,
            'role' => $this->role,
            'status' => $this->status,
            'published' => $this->published,
            'avatar' => $this->avatar,
            'salary' => $this->salary,
            'bankAccount' => $this->bankAccount,
            'paymentMethod' => $this->paymentMethod,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'user' => $this->user,
            'phone' => $this->contact,
            'account' => $this->bankAccount,
            'staffRoleId' => $this->staffRoleId,
            'roleId' => $this->staffRoleId,
            'nid' => $this->nid,
            'emergencyContact' => $this->emergencyContact,
            'loginMethod' => $this->loginMethod,
            'hasPin' => $this->hasPin,
            'locationIds' => $this->locationIds,
            'lastActiveAt' => $this->lastActiveAt,
            'invitedAt' => $this->invitedAt,
        ];
    }
}
