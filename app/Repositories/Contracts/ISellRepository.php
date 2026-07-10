<?php

namespace App\Repositories\Contracts;

use App\Models\Sell;

interface ISellRepository
{
    /**
     * Find all sells for a company with filters and pagination
     */
    public function findByCompany(int $companyId, array $filters): mixed;

    /**
     * Find a sell by ID within a company scope
     */
    public function findByIdAndCompany(int $id, int $companyId): ?Sell;

    /**
     * Find a sell by invoice number within a company scope
     */
    public function findByInvoiceAndCompany(string $invoiceNo, int $companyId): ?Sell;

    /**
     * Create a new sell
     */
    public function create(array $data): Sell;

    /**
     * Update a sell
     */
    public function update(int $id, array $data): Sell;

    /**
     * Delete a sell
     */
    public function delete(int $id): bool;

    /**
     * Get statistics for sells
     */
    public function getStats(int $companyId): array;

    public function getWeeklyOrders(int $companyId): array;

    public function getMonthlyRevenue(int $companyId): array;

    /**
     * Check if invoice number exists for company
     */
    public function invoiceExists(string $invoiceNo, int $companyId): bool;
}
