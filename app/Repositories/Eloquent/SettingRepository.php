<?php

namespace App\Repositories\Eloquent;

use App\Models\Setting;
use App\Repositories\Contracts\ISettingRepository;

class SettingRepository implements ISettingRepository
{
    public function __construct(private readonly Setting $model)
    {
    }

    public function findByCompany(int $companyId): ?Setting
    {
        return $this->model
            ->where('company_id', $companyId)
            ->first();
    }

    public function upsert(int $companyId, array $data): Setting
    {
        return $this->model->updateOrCreate(
            ['company_id' => $companyId],
            $data
        );
    }
}
