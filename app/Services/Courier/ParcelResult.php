<?php

namespace App\Services\Courier;

final class ParcelResult
{
    public function __construct(
        public readonly string $consignmentId,
        public readonly string $trackingCode,
        public readonly ?string $rawStatus = null,
        public readonly ?float $deliveryFee = null,
        public readonly array $raw = [],
    ) {
    }
}
