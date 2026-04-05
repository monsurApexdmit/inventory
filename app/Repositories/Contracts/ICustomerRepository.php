<?php

namespace App\Repositories\Contracts;

use App\Models\Customer;

interface ICustomerRepository
{
    public function findByCompany(int $companyId, array $filters): mixed;

    public function findByIdAndCompany(int $id, int $companyId): ?Customer;

    public function findByEmailAndCompany(string $email, int $companyId): ?Customer;

    public function create(array $data): Customer;

    public function update(int $id, array $data): Customer;

    public function delete(int $id): bool;
}
