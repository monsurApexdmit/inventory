<?php

namespace App\DTOs\CustomerReturn;

use App\DTOs\BaseDTO;

/**
 * DTO for Customer Return Response
 */
class CustomerReturnDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly ?int $customerId,
        public readonly string $returnNumber,
        public readonly string $customerName,
        public readonly ?int $orderId,
        public readonly ?string $orderNumber,
        public readonly float $totalAmount,
        public readonly string $status,
        public readonly ?string $processedDate,
        public readonly string $refundMethod,
        public readonly ?string $notes,
        public readonly ?string $processedBy,
        public readonly string $requestDate,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?array $items = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'customerId' => $this->customerId,
            'returnNumber' => $this->returnNumber,
            'customerName' => $this->customerName,
            'orderId' => $this->orderId,
            'orderNumber' => $this->orderNumber,
            'totalAmount' => $this->totalAmount,
            'status' => $this->status,
            'processedDate' => $this->processedDate,
            'refundMethod' => $this->refundMethod,
            'notes' => $this->notes,
            'processedBy' => $this->processedBy,
            'requestDate' => $this->requestDate,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'items' => $this->items,
        ];
    }
}
