<?php

namespace App\Repositories\Contracts;

use App\Models\Product;

interface IProductRepository
{
    public function findByCompany(int $companyId, array $filters): mixed;

    public function filteredQuery(int $companyId, array $filters, bool $withRelations = false): \Illuminate\Database\Eloquent\Builder;

    public function findByIdAndCompany(int $id, int $companyId): ?Product;

    public function create(array $data): Product;

    public function update(int $id, array $data): Product;

    public function delete(int $id): bool;
}
