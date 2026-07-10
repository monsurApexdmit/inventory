<?php

namespace App\Repositories\Contracts;

interface IPaymentRepository
{
    public function findByCompanyId(int $companyId, int $perPage = 15): mixed;

    public function create(array $data);
}
