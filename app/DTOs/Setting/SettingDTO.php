<?php

namespace App\DTOs\Setting;

use App\DTOs\BaseDTO;

/**
 * DTO for Setting Response
 */
class SettingDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly ?array $generalSettings,
        public readonly ?array $taxSettings,
        public readonly ?array $shippingSettings,
        public readonly ?array $paymentSettings,
        public readonly ?array $businessSettings,
        public readonly ?array $regionalSettings,
        public readonly ?array $notificationSettings,
        public readonly ?array $storeHours,
        public readonly ?string $logoUrl,
        public readonly ?string $bannerUrl,
        public readonly ?int $uploadedBy,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'generalSettings' => $this->generalSettings,
            'taxSettings' => $this->taxSettings,
            'shippingSettings' => $this->shippingSettings,
            'paymentSettings' => $this->paymentSettings,
            'businessSettings' => $this->businessSettings,
            'regionalSettings' => $this->regionalSettings,
            'notificationSettings' => $this->notificationSettings,
            'storeHours' => $this->storeHours,
            'logoUrl' => $this->logoUrl,
            'bannerUrl' => $this->bannerUrl,
            'uploadedBy' => $this->uploadedBy,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
