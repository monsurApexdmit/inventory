<?php

namespace App\Repositories\Contracts;

use App\Models\ShippingAddress;

interface IShippingAddressRepository
{
    public function findByCompany(int $companyId, array $filters): array;

    public function findByIdAndCompany(int $id, int $companyId): ?ShippingAddress;

    public function create(array $data): ShippingAddress;

    public function update(int $id, array $data): ShippingAddress;

    public function delete(int $id): bool;

    public function setDefault(int $id, int $customerId): ShippingAddress;
}
