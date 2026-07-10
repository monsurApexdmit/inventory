<?php

namespace App\Repositories\Contracts;

use App\Models\CustomerReturn;

interface ICustomerReturnRepository
{
    public function findByCompany(int $companyId, array $filters): mixed;

    public function findByIdAndCompany(int $id, int $companyId): ?CustomerReturn;

    public function findByCustomer(int $customerId, int $companyId, array $filters): mixed;

    public function create(array $data): CustomerReturn;

    public function update(int $id, array $data): CustomerReturn;

    public function delete(int $id): bool;

    public function getStats(int $companyId): array;
}
