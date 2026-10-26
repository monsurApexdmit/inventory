<?php

namespace App\DTOs\Coupon;

use App\DTOs\BaseDTO;

class CouponDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly string $campaignName,
        public readonly string $code,
        public readonly float $discount,
        public readonly string $type,
        public readonly string $startDate,
        public readonly ?string $endDate,
        public readonly bool $status,
        public readonly ?string $image,
        public readonly ?int $uploadedBy,
        public readonly ?int $usageLimit,
        public readonly ?int $usageLimitPerUser,
        public readonly int $timesUsed,
        public readonly float $minOrderAmount,
        public readonly ?float $maxDiscount,
        public readonly ?string $applicableToCategories,
        public readonly ?string $applicableToProducts,
        public readonly bool $freeShipping,
        public readonly bool $stackable,
        public readonly bool $autoApply,
        public readonly int $priority,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly string $appliesTo = 'all',
        public readonly array $categoryIds = [],
        public readonly array $productIds = [],
        public readonly bool $channelPos = true,
        public readonly bool $channelOnline = true,
        public readonly bool $oncePerCustomer = false,
        public readonly int $used = 0,
        public readonly float $revenue = 0.0,
        public readonly string $phase = 'active',
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'campaignName' => $this->campaignName,
            'code' => $this->code,
            'discount' => $this->discount,
            'type' => $this->type,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'status' => $this->status,
            'image' => $this->image,
            'uploadedBy' => $this->uploadedBy,
            'usageLimit' => $this->usageLimit,
            'usageLimitPerUser' => $this->usageLimitPerUser,
            'timesUsed' => $this->timesUsed,
            'minOrderAmount' => $this->minOrderAmount,
            'maxDiscount' => $this->maxDiscount,
            'applicableToCategories' => $this->applicableToCategories,
            'applicableToProducts' => $this->applicableToProducts,
            'freeShipping' => $this->freeShipping,
            'stackable' => $this->stackable,
            'autoApply' => $this->autoApply,
            'priority' => $this->priority,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'appliesTo' => $this->appliesTo,
            'categoryIds' => $this->categoryIds,
            'productIds' => $this->productIds,
            'channelPos' => $this->channelPos,
            'channelOnline' => $this->channelOnline,
            'oncePerCustomer' => $this->oncePerCustomer,
            'used' => $this->used,
            'revenue' => $this->revenue,
            'phase' => $this->phase,
        ];
    }
}
