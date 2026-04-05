<?php

namespace App\DTOs\Company;

use App\DTOs\BaseDTO;

/**
 * DTO for Company Response
 * Represents a company (SaaS multi-tenant)
 */
class CompanyDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $industry,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $website,
        public readonly ?string $country,
        public readonly ?string $address,
        public readonly ?string $businessType,
        public readonly ?string $taxId,
        public readonly ?string $currency,
        public readonly ?string $timezone,
        public readonly ?string $language,
        public readonly string $status,
        public readonly ?string $logo,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /**
     * Convert DTO to array for API response
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'industry' => $this->industry,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'country' => $this->country,
            'address' => $this->address,
            'businessType' => $this->businessType,
            'taxId' => $this->taxId,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'language' => $this->language,
            'status' => $this->status,
            'logo' => $this->logo,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
