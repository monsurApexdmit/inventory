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
        public readonly ?int $locationId = null,
        public readonly ?string $locationName = null,
        public readonly ?string $paidTo = null,
        public readonly ?string $nextDueDate = null,
        public readonly string $source = 'manual',
        public readonly bool $locked = false,
        public readonly ?string $categoryName = null,
        public readonly ?string $categoryColor = null,
        public readonly ?string $vendorName = null,
        public readonly bool $voided = false,
        public readonly ?string $voidedAt = null,
        public readonly ?string $voidReason = null,
        public readonly ?string $voidedByName = null,
        public readonly bool $periodClosed = false,
        public readonly bool $editable = true,
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
            'locationId' => $this->locationId,
            'locationName' => $this->locationName,
            'paidTo' => $this->paidTo,
            'nextDueDate' => $this->nextDueDate,
            'source' => $this->source,
            'locked' => $this->locked,
            'categoryName' => $this->categoryName,
            'categoryColor' => $this->categoryColor,
            'vendorName' => $this->vendorName,
            'voided' => $this->voided,
            'voidedAt' => $this->voidedAt,
            'voidReason' => $this->voidReason,
            'voidedByName' => $this->voidedByName,
            'periodClosed' => $this->periodClosed,
            'editable' => $this->editable,
        ];
    }
}
