<?php

namespace App\DTOs\Shipping;

use App\DTOs\BaseDTO;

/**
 * DTO for Shipping Address Response
 */
class ShippingAddressDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly ?int $customerId,
        public readonly string $fullName,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly string $addressLine1,
        public readonly ?string $addressLine2,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $postalCode,
        public readonly ?string $country,
        public readonly bool $isDefault,
        public readonly ?string $addressType,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'customerId' => $this->customerId,
            'fullName' => $this->fullName,
            'phone' => $this->phone,
            'email' => $this->email,
            'addressLine1' => $this->addressLine1,
            'addressLine2' => $this->addressLine2,
            'city' => $this->city,
            'state' => $this->state,
            'postalCode' => $this->postalCode,
            'country' => $this->country,
            'isDefault' => $this->isDefault,
            'addressType' => $this->addressType,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
