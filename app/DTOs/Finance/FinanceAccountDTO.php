<?php

namespace App\DTOs\Finance;

use App\DTOs\BaseDTO;

class FinanceAccountDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly string $typeLabel,
        public readonly array $methodKeys,
        public readonly array $methodLabels,
        public readonly bool $receivesUnmapped,
        public readonly float $openingBalance,
        public readonly bool $isDefault,
        public readonly bool $isActive,
        public readonly ?string $note,
        public readonly float $moneyIn,
        public readonly float $moneyOut,
        public readonly float $balance,
        public readonly int $transactionCount,
        public readonly ?string $lastActivityDate,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'typeLabel' => $this->typeLabel,
            'methodKeys' => $this->methodKeys,
            'methodLabels' => $this->methodLabels,
            'receivesUnmapped' => $this->receivesUnmapped,
            'openingBalance' => $this->openingBalance,
            'isDefault' => $this->isDefault,
            'isActive' => $this->isActive,
            'note' => $this->note,
            'moneyIn' => $this->moneyIn,
            'moneyOut' => $this->moneyOut,
            'balance' => $this->balance,
            'transactionCount' => $this->transactionCount,
            'lastActivityDate' => $this->lastActivityDate,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
