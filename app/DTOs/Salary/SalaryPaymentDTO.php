<?php

namespace App\DTOs\Salary;

use App\DTOs\BaseDTO;

/**
 * DTO for Salary Payment Response
 * Used for all endpoints returning salary payment data
 */
class SalaryPaymentDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $staffId,
        public readonly string $month,
        public readonly float $amount,
        public readonly float $paidAmount,
        public readonly string $status, // pending, partial, paid
        public readonly ?string $paymentDate,
        public readonly ?string $remarks,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?array $staff = null, // Nested staff info
    ) {}

    /**
     * Convert DTO to array for API response
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'staffId' => $this->staffId,
            'month' => $this->month,
            'amount' => $this->amount,
            'paidAmount' => $this->paidAmount,
            'status' => $this->status,
            'paymentDate' => $this->paymentDate,
            'remarks' => $this->remarks,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'staff' => $this->staff,
        ];
    }
}
