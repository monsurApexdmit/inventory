<?php

namespace App\Repositories\Contracts;

use App\Models\StaffRole;

interface IStaffRoleRepository
{
    public function findAllByCompany(int $companyId): array;

    public function findByIdAndCompany(int $id, int $companyId): ?StaffRole;

    public function create(array $data): StaffRole;

    public function update(int $id, array $data): StaffRole;

    public function delete(int $id): bool;
}
