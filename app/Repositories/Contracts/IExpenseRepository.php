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

    public function getListStats(int $companyId, array $filters): array;

    public function getTabCounts(int $companyId, array $filters): array;

    public function getTemplates(int $companyId, int $limit = 6): array;

    public function getDueRecurring(\Carbon\CarbonInterface $today);
}
