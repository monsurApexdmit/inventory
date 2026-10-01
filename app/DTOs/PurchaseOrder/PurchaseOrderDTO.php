<?php

namespace App\DTOs\PurchaseOrder;

use App\DTOs\BaseDTO;

class PurchaseOrderDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly int $vendorId,
        public readonly string $vendorName,
        public readonly ?int $locationId,
        public readonly ?string $locationName,
        public readonly string $poNumber,
        public readonly string $status,
        public readonly ?string $expectedDate,
        public readonly ?string $notes,
        public readonly float $totalAmount,
        public readonly float $paidAmount,
        public readonly float $dueAmount,
        public readonly string $paymentStatus,
        public readonly array $payments,
        public readonly array $items,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'companyId'    => $this->companyId,
            'vendorId'     => $this->vendorId,
            'vendorName'   => $this->vendorName,
            'locationId'   => $this->locationId,
            'locationName' => $this->locationName,
            'poNumber'     => $this->poNumber,
            'status'       => $this->status,
            'expectedDate' => $this->expectedDate,
            'notes'        => $this->notes,
            'totalAmount'  => $this->totalAmount,
            'paidAmount'   => $this->paidAmount,
            'dueAmount'    => $this->dueAmount,
            'paymentStatus' => $this->paymentStatus,
            'payments'     => $this->payments,
            'items'        => $this->items,
            'createdAt'    => $this->createdAt,
            'updatedAt'    => $this->updatedAt,
        ];
    }
}
