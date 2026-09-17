<?php

namespace App\Repositories\Contracts;

use App\Models\ExpenseCategory;

interface IExpenseCategoryRepository
{
    public function findByCompany(int $companyId, array $filters): mixed;

    public function findByIdAndCompany(int $id, int $companyId): ?ExpenseCategory;

    public function findByNameAndCompany(string $name, int $companyId): ?ExpenseCategory;

    public function create(array $data): ExpenseCategory;

    public function update(int $id, array $data): ExpenseCategory;

    public function delete(int $id): bool;
}
