<?php

namespace App\Repositories\Contracts;

use App\Models\StockTransfer;
use Illuminate\Pagination\LengthAwarePaginator;

interface IStockTransferRepository extends BaseRepositoryInterface
{
    /**
     * Find transfer by ID and company scope
     */
    public function findByIdAndCompany(int $id, int $companyId): ?StockTransfer;

    /**
     * Find all transfers for a company with filters
     */
    public function findByCompany(int $companyId, array $filters = []): LengthAwarePaginator;

    /**
     * Get products available in a location by location ID and company scope
     */
    public function getProductsByLocation(int $companyId, int $locationId, array $filters = []): LengthAwarePaginator;

    /**
     * Update transfer and return fresh instance
     */
    public function update(int $id, array $data): StockTransfer;
}
