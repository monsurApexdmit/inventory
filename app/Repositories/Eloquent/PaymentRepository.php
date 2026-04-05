<?php

namespace App\Repositories\Eloquent;

use App\Models\Payment;
use App\Repositories\Contracts\IPaymentRepository;

class PaymentRepository implements IPaymentRepository
{
    public function __construct(private readonly Payment $model)
    {
    }

    public function findByCompanyId(int $companyId, int $perPage = 15): mixed
    {
        return $this->model
            ->where('company_id', $companyId)
            ->paginate($perPage);
    }
}
