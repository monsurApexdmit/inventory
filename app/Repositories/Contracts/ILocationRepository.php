<?php

namespace App\Repositories\Contracts;

use App\Models\Location;

interface ILocationRepository
{
    public function findByCompany(int $companyId): array;

    public function findByIdAndCompany(int $id, int $companyId): ?Location;

    public function create(array $data): Location;

    public function update(int $id, array $data): Location;

    public function delete(int $id): bool;

    public function countByCompany(int $companyId): int;
}
