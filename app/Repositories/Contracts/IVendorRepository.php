<?php

namespace App\Repositories\Contracts;

use App\Models\Vendor;

interface IVendorRepository
{
    public function findByCompany(int $companyId, array $filters): mixed;

    public function findByIdAndCompany(int $id, int $companyId): ?Vendor;

    public function findByEmailAndCompany(string $email, int $companyId): ?Vendor;

    public function create(array $data): Vendor;

    public function update(int $id, array $data): Vendor;

    public function delete(int $id): bool;

    /** Vendors with grouped PO aggregates; returns ['rows' => Collection<Vendor>, 'total' => int]. */
    public function overview(int $companyId, array $filters): array;

    /** Company-wide supplier stats for the overview screen. */
    public function overviewStats(int $companyId): array;

    /** Vendor with aggregate attributes (purchased, po_due, ...) or null. */
    public function findWithAggregates(int $id, int $companyId): ?Vendor;

    /** @param int[] $vendorIds @return array<int,string|null> */
    public function itemsSummaries(array $vendorIds): array;
}
