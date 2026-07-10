<?php

namespace App\Repositories\Eloquent;

use App\Models\Staff;
use App\Repositories\Contracts\IStaffRepository;

class StaffRepository implements IStaffRepository
{
    public function __construct(private readonly Staff $model)
    {
    }

    public function findAllByCompany(int $companyId, int $perPage = 15): mixed
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['user'])
            ->paginate($perPage);
    }

    public function findByIdAndCompany(int $id, int $companyId): ?Staff
    {
        return $this->model
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();
    }

    public function findByUserId(int $userId): ?Staff
    {
        return Staff::where('user_id', $userId)->first();
    }

    public function create(array $data): Staff
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Staff
    {
        $record = $this->model->findOrFail($id);
        $record->update($data);

        return $record;
    }

    public function delete(int $id): bool
    {
        return $this->model->destroy($id) > 0;
    }

    public function getStats(int $companyId): array
    {
        $stats = $this->model
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = "inactive" THEN 1 ELSE 0 END) as inactive
            ')
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'active' => (int) ($stats->active ?? 0),
            'inactive' => (int) ($stats->inactive ?? 0),
        ];
    }
}
