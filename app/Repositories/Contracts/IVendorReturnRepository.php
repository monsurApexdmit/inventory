<?php

namespace App\Repositories\Contracts;

use App\Models\VendorReturn;

interface IVendorReturnRepository
{
    public function findByCompany(int $companyId, array $filters): mixed;

    public function findByIdAndCompany(int $id, int $companyId): ?VendorReturn;

    public function findByVendor(int $vendorId, int $companyId, array $filters): mixed;

    public function create(array $data): VendorReturn;

    public function update(int $id, array $data): VendorReturn;

    public function delete(int $id): bool;

    public function getStats(int $companyId): array;
}
