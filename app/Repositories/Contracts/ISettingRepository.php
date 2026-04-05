<?php

namespace App\Repositories\Contracts;

use App\Models\Setting;

interface ISettingRepository
{
    public function findByCompany(int $companyId): ?Setting;

    public function upsert(int $companyId, array $data): Setting;
}
