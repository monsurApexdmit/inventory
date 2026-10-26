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
        public readonly string $type = 'Shop',
        public readonly ?string $district = null,
        public readonly ?string $phone = null,
        public readonly ?string $manager = null,
        public readonly bool $sellsInPos = false,
        public readonly bool $shipsOnline = false,
        public readonly bool $isPickupPoint = false,
        public readonly float $stockValue = 0,
        public readonly int $skus = 0,
        public readonly int $units = 0,
        public readonly int $staffCount = 0,
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
            'type' => $this->type,
            'district' => $this->district,
            'phone' => $this->phone,
            'manager' => $this->manager,
            'sellsInPos' => $this->sellsInPos,
            'shipsOnline' => $this->shipsOnline,
            'isPickupPoint' => $this->isPickupPoint,
            'stockValue' => $this->stockValue,
            'skus' => $this->skus,
            'units' => $this->units,
            'staffCount' => $this->staffCount,
        ];
    }
}
