<?php

namespace App\Services\Courier;

use App\Models\OrderShipment;

/** One courier provider bound to one merchant account. All failures throw CourierException. */
interface CourierDriver
{
    public function provider(): string;

    public function label(): string;

    /** @return array{ok: bool, message: string, balance?: float|null, stores?: array|null} */
    public function testConnection(): array;

    public function createParcel(ParcelData $parcel): ParcelResult;

    /**
     * @param ParcelData[] $parcels
     * @return array<string, ParcelResult|CourierException> keyed by ParcelData::$ref
     */
    public function bulkCreate(array $parcels): array;

    public function fetchStatus(OrderShipment $shipment): StatusResult;

    public function supportsCancel(): bool;

    public function cancel(OrderShipment $shipment): void;

    /** @return array{available: bool, deliveryFee: float|null, codFeePercent: float|null, message: string|null} */
    public function quote(array $params): array;

    /** @return array<int, array{id: int, name: string}> */
    public function locations(string $type, ?int $parentId): array;

    /** @return array<int, array> */
    public function stores(): array;

    /** @return array|null null when the courier has no such API */
    public function customerSuccessRate(string $phone): ?array;

    /** Courier-side tracking events, newest first; null when the courier has no events API. */
    public function trackingEvents(OrderShipment $shipment): ?array;

    public function trackingUrl(string $trackingCode, ?string $phone = null): ?string;
}
