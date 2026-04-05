<?php

namespace App\DTOs\Customer;

use App\DTOs\BaseDTO;

/**
 * DTO for Customer Response
 */
class CustomerDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly ?int $userId,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly ?string $address,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $zipCode,
        public readonly ?string $country,
        public readonly string $customerType,
        public readonly string $status,
        public readonly ?string $notes,
        public readonly float $storeCredit,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'userId' => $this->userId,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zipCode' => $this->zipCode,
            'country' => $this->country,
            'customerType' => $this->customerType,
            'status' => $this->status,
            'notes' => $this->notes,
            'storeCredit' => $this->storeCredit,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
