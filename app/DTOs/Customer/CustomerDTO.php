<?php

namespace App\DTOs\Customer;

use App\DTOs\BaseDTO;

/**
 * DTO for Customer Response
 */
class CustomerDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly ?int $userId,
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $address,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $zipCode,
        public readonly ?string $country,
        public readonly string $customerType,
        public readonly string $status,
        public readonly ?string $notes,
        public readonly float $storeCredit,
        public readonly int $totalOrders,
        public readonly float $totalSpent,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly float $dueAmount = 0,
        public readonly ?string $lastOrderAt = null,
        public readonly bool $duplicatePhone = false,
        public readonly string $type = 'individual',
        public readonly ?string $businessName = null,
        public readonly ?string $licenceBin = null,
        public readonly string $customerGroup = 'retail',
        public readonly float $creditLimit = 0,
        public readonly float $openingBalance = 0,
        public readonly float $creditUsed = 0,
        public readonly float $creditUsedPercent = 0,
        public readonly ?string $birthday = null,
        public readonly array $tags = [],
        public readonly bool $smsOptIn = true,
        public readonly ?string $district = null,
        public readonly ?string $area = null,
        public readonly ?array $recentOrders = null,
    ) {}

    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'userId' => $this->userId,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zipCode' => $this->zipCode,
            'country' => $this->country,
            'customerType' => $this->customerType,
            'status' => $this->status,
            'notes' => $this->notes,
            'storeCredit' => $this->storeCredit,
            'totalOrders' => $this->totalOrders,
            'totalSpent' => $this->totalSpent,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'dueAmount' => $this->dueAmount,
            'lastOrderAt' => $this->lastOrderAt,
            'duplicatePhone' => $this->duplicatePhone,
            'type' => $this->type,
            'businessName' => $this->businessName,
            'licenceBin' => $this->licenceBin,
            'customerGroup' => $this->customerGroup,
            'creditLimit' => $this->creditLimit,
            'openingBalance' => $this->openingBalance,
            'creditUsed' => $this->creditUsed,
            'creditUsedPercent' => $this->creditUsedPercent,
            'birthday' => $this->birthday,
            'tags' => $this->tags,
            'smsOptIn' => $this->smsOptIn,
            'district' => $this->district,
            'area' => $this->area,
        ];
        if ($this->recentOrders !== null) {
            $data['recentOrders'] = $this->recentOrders;
        }

        return $data;
    }
}
