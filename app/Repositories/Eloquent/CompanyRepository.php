<?php

namespace App\Repositories\Eloquent;

use App\Models\Company;
use App\Repositories\Contracts\ICompanyRepository;

class CompanyRepository implements ICompanyRepository
{
    public function __construct(private readonly Company $model) {}

    public function findById(int $id): ?Company
    {
        return $this->model->find($id);
    }

    public function create(array $data): Company
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Company
    {
        $company = $this->model->findOrFail($id);
        $company->update($data);

        return $company->fresh();
    }
}
