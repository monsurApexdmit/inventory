<?php

namespace App\DTOs\Billing;

use App\DTOs\BaseDTO;

/**
 * DTO for Subscription Response
 */
class SubscriptionDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly int $planId,
        public readonly ?string $planName,
        public readonly int $price,
        public readonly string $billingPeriod,
        public readonly string $status,
        public readonly ?string $currentPeriodStart,
        public readonly ?string $currentPeriodEnd,
        public readonly ?string $nextBillingDate,
        public readonly bool $autoRenew,
        public readonly ?string $stripeSubscriptionId,
        public readonly ?string $cancelledAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'planId' => $this->planId,
            'planName' => $this->planName,
            'price' => $this->price,
            'billingPeriod' => $this->billingPeriod,
            'status' => $this->status,
            'currentPeriodStart' => $this->currentPeriodStart,
            'currentPeriodEnd' => $this->currentPeriodEnd,
            'nextBillingDate' => $this->nextBillingDate,
            'autoRenew' => $this->autoRenew,
            'stripeSubscriptionId' => $this->stripeSubscriptionId,
            'cancelledAt' => $this->cancelledAt,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
