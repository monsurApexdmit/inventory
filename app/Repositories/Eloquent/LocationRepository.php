<?php

namespace App\Repositories\Eloquent;

use App\Models\Location;
use App\Repositories\Contracts\ILocationRepository;

class LocationRepository implements ILocationRepository
{
    public function __construct(private readonly Location $model)
    {
    }

    public function findByCompany(int $companyId): array
    {
        return $this->model
            ->where('company_id', $companyId)
            ->get()
            ->all();
    }

    public function findByIdAndCompany(int $id, int $companyId): ?Location
    {
        return $this->model
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();
    }

    public function create(array $data): Location
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Location
    {
        $record = $this->model->findOrFail($id);
        $record->fill($data)->save();

        return $record;
    }

    public function delete(int $id): bool
    {
        return $this->model->destroy($id) > 0;
    }

    public function countByCompany(int $companyId): int
    {
        return $this->model->where('company_id', $companyId)->count();
    }
}
