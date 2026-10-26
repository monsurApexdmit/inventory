<?php

namespace App\Repositories\Contracts;

use App\Models\OrderShipment;

interface IOrderShipmentRepository
{
    public function findByCompany(int $companyId, array $filters): mixed;

    public function findByIdAndCompany(int $id, int $companyId): ?OrderShipment;

    public function findByTrackingNumber(string $trackingNumber): ?OrderShipment;

    public function create(array $data): OrderShipment;

    public function update(int $id, array $data): OrderShipment;

    public function readyToShip(int $companyId, array $filters): mixed;

    public function readyToShipQuery(int $companyId);

    public function trackingExists(int $companyId, string $trackingNumber, ?int $ignoreId = null): bool;

    public function getStats(int $companyId): array;
}
