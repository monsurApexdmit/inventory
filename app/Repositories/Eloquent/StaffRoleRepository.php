<?php

namespace App\Repositories\Eloquent;

use App\Models\StaffRole;
use App\Repositories\Contracts\IStaffRoleRepository;

class StaffRoleRepository implements IStaffRoleRepository
{
    public function __construct(private readonly StaffRole $model)
    {
    }

    public function findAllByCompany(int $companyId): array
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with('permissions.permission')
            ->get()
            ->all();
    }

    public function findByIdAndCompany(int $id, int $companyId): ?StaffRole
    {
        return $this->model
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->with('permissions.permission')
            ->first();
    }

    public function create(array $data): StaffRole
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): StaffRole
    {
        $record = $this->model->findOrFail($id);
        $record->update($data);

        return $record;
    }

    public function delete(int $id): bool
    {
        return $this->model->destroy($id) > 0;
    }
}
