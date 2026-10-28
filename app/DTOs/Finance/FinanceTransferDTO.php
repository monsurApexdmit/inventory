<?php

namespace App\DTOs\Finance;

use App\DTOs\BaseDTO;

class FinanceTransferDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $fromAccountId,
        public readonly ?string $fromAccountName,
        public readonly int $toAccountId,
        public readonly ?string $toAccountName,
        public readonly float $amount,
        public readonly ?string $date,
        public readonly ?string $reference,
        public readonly ?string $note,
        public readonly ?int $createdBy,
        public readonly ?string $createdByName,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fromAccountId' => $this->fromAccountId,
            'fromAccountName' => $this->fromAccountName,
            'toAccountId' => $this->toAccountId,
            'toAccountName' => $this->toAccountName,
            'amount' => $this->amount,
            'date' => $this->date,
            'reference' => $this->reference,
            'note' => $this->note,
            'createdBy' => $this->createdBy,
            'createdByName' => $this->createdByName,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
