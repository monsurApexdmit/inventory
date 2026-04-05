<?php

namespace App\Repositories\Contracts;

use App\Models\Vendor;

interface IVendorRepository
{
    public function findByCompany(int $companyId, array $filters): mixed;

    public function findByIdAndCompany(int $id, int $companyId): ?Vendor;

    public function findByEmailAndCompany(string $email, int $companyId): ?Vendor;

    public function create(array $data): Vendor;

    public function update(int $id, array $data): Vendor;

    public function delete(int $id): bool;
}
