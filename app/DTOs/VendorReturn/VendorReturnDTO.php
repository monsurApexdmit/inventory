<?php

namespace App\DTOs\VendorReturn;

use App\DTOs\BaseDTO;

/**
 * DTO for Vendor Return Response
 */
class VendorReturnDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly int $vendorId,
        public readonly string $returnNumber,
        public readonly string $vendorName,
        public readonly float $totalAmount,
        public readonly string $status,
        public readonly ?string $completedDate,
        public readonly string $creditType,
        public readonly ?string $notes,
        public readonly string $returnDate,
        public readonly ?string $createdBy,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?array $items = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'vendorId' => $this->vendorId,
            'returnNumber' => $this->returnNumber,
            'vendorName' => $this->vendorName,
            'totalAmount' => $this->totalAmount,
            'status' => $this->status,
            'completedDate' => $this->completedDate,
            'creditType' => $this->creditType,
            'notes' => $this->notes,
            'returnDate' => $this->returnDate,
            'createdBy' => $this->createdBy,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'items' => $this->items,
        ];
    }
}
