<?php

namespace App\DTOs\Inventory;

use App\DTOs\BaseDTO;

class LocationInventoryDTO extends BaseDTO
{
    public function __construct(
        public readonly int $locationId,
        public readonly string $locationName,
        public readonly int $quantity,
    ) {}

    public function toArray(): array
    {
        return [
            'locationId' => $this->locationId,
            'locationName' => $this->locationName,
            'quantity' => $this->quantity,
        ];
    }
}
