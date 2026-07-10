<?php

namespace App\Repositories\Contracts;

use App\Models\PurchaseOrder;
use Illuminate\Pagination\LengthAwarePaginator;

interface IPurchaseOrderRepository
{
    public function findByCompany(int $companyId, array $filters): LengthAwarePaginator;
    public function findById(int $id, int $companyId): ?PurchaseOrder;
    public function create(array $data): PurchaseOrder;
    public function update(PurchaseOrder $po, array $data): PurchaseOrder;
    public function delete(int $id, int $companyId): void;
    public function nextPoNumber(int $companyId): string;
    public function getStats(int $companyId): array;
}
