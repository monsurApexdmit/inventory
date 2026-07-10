<?php

namespace App\Repositories\Contracts;

use App\Models\CompanySettings;

interface ICompanySettingsRepository
{
    public function findByCompanyId(int $companyId): ?CompanySettings;

    public function upsert(int $companyId, array $data): CompanySettings;
}
