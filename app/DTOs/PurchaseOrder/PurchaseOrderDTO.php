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
        public readonly string $discountType = 'flat',
        public readonly float $discountValue = 0.0,
        public readonly float $discountAmount = 0.0,
        public readonly float $taxRate = 0.0,
        public readonly float $taxAmount = 0.0,
        public readonly float $shippingCost = 0.0,
        public readonly float $subtotal = 0.0,
        public readonly string $itemsSummary = 'No items',
        public readonly int $unitsOrdered = 0,
        public readonly int $unitsReceived = 0,
        public readonly bool $isOverdue = false,
        public readonly ?string $sentAt = null,
        public readonly ?string $receivedAt = null,
        public readonly array $receipts = [],
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
            'discountType'   => $this->discountType,
            'discountValue'  => $this->discountValue,
            'discountAmount' => $this->discountAmount,
            'taxRate'        => $this->taxRate,
            'taxAmount'      => $this->taxAmount,
            'shippingCost'   => $this->shippingCost,
            'subtotal'       => $this->subtotal,
            'itemsSummary'   => $this->itemsSummary,
            'unitsOrdered'   => $this->unitsOrdered,
            'unitsReceived'  => $this->unitsReceived,
            'isOverdue'      => $this->isOverdue,
            'sentAt'         => $this->sentAt,
            'receivedAt'     => $this->receivedAt,
            'receipts'       => $this->receipts,
        ];
    }
}
