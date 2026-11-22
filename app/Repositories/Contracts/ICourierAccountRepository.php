<?php

namespace App\Repositories\Contracts;

use App\Models\CourierAccount;
use App\Models\OrderShipment;
use Illuminate\Support\Collection;

interface ICourierAccountRepository
{
    /** @return Collection<int, CourierAccount> */
    public function listByCompany(int $companyId, bool $activeOnly = false): Collection;

    public function findByIdAndCompany(int $id, int $companyId): ?CourierAccount;

    public function findByProvider(int $companyId, string $provider): ?CourierAccount;

    public function findDefault(int $companyId): ?CourierAccount;

    public function findByWebhookToken(string $provider, string $token): ?CourierAccount;

    public function create(array $data): CourierAccount;

    public function update(CourierAccount $account, array $data): CourierAccount;

    public function delete(CourierAccount $account): void;

    public function clearDefault(int $companyId, ?int $exceptId = null): void;

    public function hasOpenShipments(CourierAccount $account): bool;

    public function findCourierShipment(int $id, int $companyId): ?OrderShipment;

    public function findShipmentByConsignment(CourierAccount $account, string $consignmentId, ?string $invoice = null): ?OrderShipment;

    /** Non-final courier shipments of active accounts that were not synced since $staleBefore. */
    public function syncCandidates(\DateTimeInterface $staleBefore, int $limit, ?int $companyId = null): Collection;
}
