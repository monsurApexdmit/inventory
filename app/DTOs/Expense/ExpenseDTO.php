<?php

namespace App\DTOs\Expense;

use App\DTOs\BaseDTO;

/**
 * DTO for Expense Response
 */
class ExpenseDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly ?int $expenseCategoryId,
        public readonly ?int $vendorId,
        public readonly string $title,
        public readonly float $amount,
        public readonly string $expenseDate,
        public readonly string $paymentMethod,
        public readonly ?string $referenceNo,
        public readonly ?string $notes,
        public readonly ?string $receiptPath,
        public readonly bool $isRecurring,
        public readonly ?string $recurringFrequency,
        public readonly ?string $recurringEndDate,
        public readonly ?int $uploadedBy,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?array $category = null,
        public readonly ?array $vendor = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'expenseCategoryId' => $this->expenseCategoryId,
            'vendorId' => $this->vendorId,
            'title' => $this->title,
            'amount' => $this->amount,
            'expenseDate' => $this->expenseDate,
            'paymentMethod' => $this->paymentMethod,
            'referenceNo' => $this->referenceNo,
            'notes' => $this->notes,
            'receiptPath' => $this->receiptPath,
            'isRecurring' => $this->isRecurring,
            'recurringFrequency' => $this->recurringFrequency,
            'recurringEndDate' => $this->recurringEndDate,
            'uploadedBy' => $this->uploadedBy,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'category' => $this->category,
            'vendor' => $this->vendor,
        ];
    }
}
