<?php

namespace App\DTOs\Billing;

use App\DTOs\BaseDTO;

/**
 * DTO for Billing Contact Response
 */
class BillingContactDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly ?string $address,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $zipCode,
        public readonly ?string $country,
        public readonly ?string $taxId,
        public readonly ?string $taxIdType,
        public readonly bool $isDefault,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zipCode' => $this->zipCode,
            'country' => $this->country,
            'taxId' => $this->taxId,
            'taxIdType' => $this->taxIdType,
            'isDefault' => $this->isDefault,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
