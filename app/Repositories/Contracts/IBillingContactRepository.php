<?php

namespace App\Repositories\Contracts;

use App\Models\BillingContact;

interface IBillingContactRepository
{
    public function findByCompanyId(int $companyId): ?BillingContact;

    public function upsert(int $companyId, array $data): BillingContact;
}
