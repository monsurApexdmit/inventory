<?php

namespace App\Repositories\Contracts;

use App\Models\SalaryPayment;

interface ISalaryPaymentRepository
{
    public function findByCompany(int $companyId, array $filters): mixed;

    public function findByIdAndCompany(int $id, int $companyId): ?SalaryPayment;

    public function findByStaffAndMonth(int $staffId, string $month): ?SalaryPayment;

    public function create(array $data): SalaryPayment;

    public function update(int $id, array $data): SalaryPayment;

    public function delete(int $id): bool;
}
