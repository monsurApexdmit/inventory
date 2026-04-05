<?php

namespace App\Repositories\Eloquent;

use App\Models\CompanySettings;
use App\Repositories\Contracts\ICompanySettingsRepository;

class CompanySettingsRepository implements ICompanySettingsRepository
{
    public function __construct(private readonly CompanySettings $model)
    {
    }

    public function findByCompanyId(int $companyId): ?CompanySettings
    {
        return $this->model->where('company_id', $companyId)->first();
    }

    public function upsert(int $companyId, array $data): CompanySettings
    {
        $data['company_id'] = $companyId;

        return $this->model->updateOrCreate(
            ['company_id' => $companyId],
            $data
        );
    }
}
