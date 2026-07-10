<?php

namespace App\Repositories\Contracts;

use App\Models\Company;

interface ICompanyRepository
{
    public function findById(int $id): ?Company;

    public function create(array $data): Company;

    public function update(int $id, array $data): Company;
}
