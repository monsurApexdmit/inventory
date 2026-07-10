<?php

namespace App\Repositories\Eloquent;

use App\Models\BillingContact;
use App\Repositories\Contracts\IBillingContactRepository;

class BillingContactRepository implements IBillingContactRepository
{
    public function __construct(private readonly BillingContact $model)
    {
    }

    public function findByCompanyId(int $companyId): ?BillingContact
    {
        return $this->model->where('company_id', $companyId)->first();
    }

    public function upsert(int $companyId, array $data): BillingContact
    {
        $data['company_id'] = $companyId;

        return $this->model->updateOrCreate(
            ['company_id' => $companyId],
            $data
        );
    }
}
