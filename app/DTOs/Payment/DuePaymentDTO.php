<?php

namespace App\DTOs\Payment;

use App\DTOs\BaseDTO;

/**
 * DTO for DuePayment Response
 */
class DuePaymentDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly string $payableType,
        public readonly int $payableId,
        public readonly string $direction,
        public readonly float $amount,
        public readonly string $paymentMethod,
        public readonly string $paymentDate,
        public readonly ?string $reference,
        public readonly ?string $notes,
        public readonly ?int $recordedBy,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'payableType' => $this->payableType,
            'payableId' => $this->payableId,
            'direction' => $this->direction,
            'amount' => $this->amount,
            'paymentMethod' => $this->paymentMethod,
            'paymentDate' => $this->paymentDate,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'recordedBy' => $this->recordedBy,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
