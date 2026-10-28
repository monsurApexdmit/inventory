<?php

namespace App\DTOs\Finance;

use App\DTOs\BaseDTO;

class FinanceEntryDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $accountId,
        public readonly ?string $accountName,
        public readonly string $kind,
        public readonly string $direction,
        public readonly float $amount,
        public readonly ?string $date,
        public readonly ?string $category,
        public readonly ?string $party,
        public readonly ?string $reference,
        public readonly ?string $note,
        public readonly ?int $createdBy,
        public readonly ?string $createdByName,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        public readonly ?int $vendorId = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'accountId' => $this->accountId,
            'accountName' => $this->accountName,
            'kind' => $this->kind,
            'direction' => $this->direction,
            'amount' => $this->amount,
            'date' => $this->date,
            'category' => $this->category,
            'party' => $this->party,
            'reference' => $this->reference,
            'note' => $this->note,
            'createdBy' => $this->createdBy,
            'createdByName' => $this->createdByName,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'vendorId' => $this->vendorId,
        ];
    }
}
