<?php

namespace App\DTOs\Marketing;

use App\DTOs\BaseDTO;

/** Admin-facing campaign (ad order) link, camelCase. See AD_ORDERS_API_CONTRACT.md §3.1. */
class CampaignLinkDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $channel,
        public readonly string $targetType,
        public readonly ?int $targetId,
        public readonly ?array $target,
        public readonly string $code,
        public readonly ?string $utmSource,
        public readonly ?string $utmMedium,
        public readonly ?string $utmCampaign,
        public readonly ?string $utmContent,
        public readonly ?string $notes,
        public readonly bool $isActive,
        public readonly ?int $createdBy,
        public readonly ?string $createdByName,
        public readonly ?string $storeBaseUrl,
        public readonly string $targetPath,
        public readonly ?string $fullUrl,
        public readonly ?string $shortUrl,
        public readonly array $stats,
        public readonly ?string $lastClickedAt,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'channel' => $this->channel,
            'targetType' => $this->targetType,
            'targetId' => $this->targetId,
            'target' => $this->target,
            'code' => $this->code,
            'utmSource' => $this->utmSource,
            'utmMedium' => $this->utmMedium,
            'utmCampaign' => $this->utmCampaign,
            'utmContent' => $this->utmContent,
            'notes' => $this->notes,
            'isActive' => $this->isActive,
            'createdBy' => $this->createdBy,
            'createdByName' => $this->createdByName,
            'storeBaseUrl' => $this->storeBaseUrl,
            'targetPath' => $this->targetPath,
            'fullUrl' => $this->fullUrl,
            'shortUrl' => $this->shortUrl,
            'stats' => $this->stats,
            'lastClickedAt' => $this->lastClickedAt,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
