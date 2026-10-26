<?php

namespace App\DTOs\Vendor;

use App\DTOs\BaseDTO;

/**
 * DTO for Vendor Response
 */
class VendorDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly ?int $userId,
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $address,
        public readonly ?string $logo,
        public readonly ?int $uploadedBy,
        public readonly string $status,
        public readonly ?string $description,
        public readonly float $totalPaid,
        public readonly float $amountPayable,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?array $user = null,
        public readonly float $poDueAmount = 0.0,
        public readonly ?string $contactPerson = null,
        public readonly ?string $district = null,
        public readonly ?string $country = null,
        public readonly ?string $paymentTerms = null,
        public readonly float $openingBalance = 0.0,
        public readonly ?string $tin = null,
        public readonly ?string $payTo = null,
        public readonly ?string $accountNumber = null,
        public readonly ?string $notes = null,
        public readonly ?int $leadTimeDays = null,
        public readonly ?int $safetyDays = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'userId' => $this->userId,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'logo' => $this->logo,
            'uploadedBy' => $this->uploadedBy,
            'status' => $this->status,
            'description' => $this->description,
            'totalPaid' => $this->totalPaid,
            'amountPayable' => $this->amountPayable,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'user' => $this->user,
            'poDueAmount' => $this->poDueAmount,
            'contactPerson' => $this->contactPerson,
            'district' => $this->district,
            'country' => $this->country,
            'paymentTerms' => $this->paymentTerms,
            'openingBalance' => $this->openingBalance,
            'tin' => $this->tin,
            'payTo' => $this->payTo,
            'accountNumber' => $this->accountNumber,
            'notes' => $this->notes,
            'leadTimeDays' => $this->leadTimeDays,
            'safetyDays' => $this->safetyDays,
        ];
    }
}
