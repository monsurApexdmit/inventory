<?php

namespace App\Services\Courier;

final class StatusResult
{
    public function __construct(
        public readonly ?string $rawStatus,
        public readonly ?float $codCollected = null,
        public readonly ?float $deliveryFee = null,
        public readonly ?string $statusAt = null,
        public readonly array $raw = [],
    ) {
    }
}
