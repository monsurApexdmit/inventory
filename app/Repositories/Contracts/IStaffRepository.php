<?php

namespace App\Repositories\Contracts;

use App\Models\Staff;

interface IStaffRepository
{
    public function findAllByCompany(int $companyId, int $perPage = 15): mixed;

    public function findByIdAndCompany(int $id, int $companyId): ?Staff;

    public function findByUserId(int $userId): ?Staff;

    public function create(array $data): Staff;

    public function update(int $id, array $data): Staff;

    public function delete(int $id): bool;
}
