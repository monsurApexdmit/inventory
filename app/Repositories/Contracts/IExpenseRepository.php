<?php

namespace App\Repositories\Contracts;

use App\Models\Expense;

interface IExpenseRepository
{
    public function findByCompany(int $companyId, array $filters): mixed;

    public function findByIdAndCompany(int $id, int $companyId): ?Expense;

    public function create(array $data): Expense;

    public function update(int $id, array $data): Expense;

    public function delete(int $id): bool;

    public function getStats(int $companyId): array;
}
