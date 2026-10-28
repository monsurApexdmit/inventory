<?php

namespace App\Repositories\Contracts;

use App\Models\FinanceTransfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface IFinanceTransferRepository
{
    /** filters: from, to (Y-m-d), accountId (either side), page, perPage */
    public function paginateByCompany(int $companyId, array $filters): LengthAwarePaginator;

    /** @return array{amount: float, count: int} */
    public function totals(int $companyId, array $filters): array;

    public function findByIdAndCompany(int $id, int $companyId): ?FinanceTransfer;

    public function create(array $data): FinanceTransfer;

    public function update(FinanceTransfer $transfer, array $data): FinanceTransfer;

    public function delete(FinanceTransfer $transfer): void;
}
