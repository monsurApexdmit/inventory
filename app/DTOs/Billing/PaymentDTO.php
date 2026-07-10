<?php

namespace App\DTOs\Billing;

use App\DTOs\BaseDTO;

/**
 * DTO for Payment Response
 */
class PaymentDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $subscriptionId,
        public readonly int $companyId,
        public readonly int $amount,
        public readonly string $status,
        public readonly ?string $paymentMethod,
        public readonly ?string $paymentDate,
        public readonly ?string $invoiceNumber,
        public readonly ?string $invoiceUrl,
        public readonly ?string $stripePaymentId,
        public readonly ?string $description,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'subscriptionId' => $this->subscriptionId,
            'companyId' => $this->companyId,
            'amount' => $this->amount,
            'status' => $this->status,
            'paymentMethod' => $this->paymentMethod,
            'paymentDate' => $this->paymentDate,
            'invoiceNumber' => $this->invoiceNumber,
            'invoiceUrl' => $this->invoiceUrl,
            'stripePaymentId' => $this->stripePaymentId,
            'description' => $this->description,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
