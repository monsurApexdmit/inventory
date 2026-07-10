<?php

namespace App\Repositories\Contracts;

use App\Models\Attribute;

interface IAttributeRepository
{
    public function findByCompany(int $companyId, array $filters): mixed;

    public function findByIdAndCompany(int $id, int $companyId): ?Attribute;

    public function findSimpleByCompany(int $companyId): mixed;

    public function getStatsByCompany(int $companyId): array;

    public function existsByNameAndCompany(string $name, int $companyId, ?int $excludeId = null): bool;

    public function create(array $data): Attribute;

    public function update(int $id, array $data): Attribute;

    public function delete(int $id): bool;

    public function bulkDelete(array $ids, int $companyId): int;
}
