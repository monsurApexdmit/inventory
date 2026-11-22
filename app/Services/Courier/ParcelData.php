<?php

namespace App\Services\Courier;

/** Everything a courier needs to create one consignment (already validated / normalized). */
final class ParcelData
{
    public function __construct(
        public readonly string $ref,                 // our reference (sell id) — used to match bulk results
        public readonly string $invoice,             // unique merchant order id at the courier
        public readonly string $recipientName,
        public readonly string $recipientPhone,      // 01XXXXXXXXX
        public readonly string $recipientAddress,
        public readonly float $codAmount,
        public readonly float $weightKg = 0.5,
        public readonly int $deliveryType = 48,
        public readonly int $itemType = 2,
        public readonly int $itemQuantity = 1,
        public readonly ?string $itemDescription = null,
        public readonly ?string $note = null,
        public readonly ?string $storeId = null,
        public readonly ?int $cityId = null,
        public readonly ?int $zoneId = null,
        public readonly ?int $areaId = null,
        public readonly ?string $recipientEmail = null,
    ) {
    }
}
