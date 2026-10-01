<?php

namespace App\Repositories\Eloquent;

use App\Models\DuePayment;
use App\Repositories\Contracts\IDuePaymentRepository;
use Illuminate\Support\Collection;

class DuePaymentRepository implements IDuePaymentRepository
{
    public function __construct(private readonly DuePayment $model)
    {
    }

    public function create(array $data): DuePayment
    {
        return $this->model->create($data);
    }

    public function findByPayable(string $type, int $id): Collection
    {
        return $this->model
            ->where('payable_type', $type)
            ->where('payable_id', $id)
            ->orderByDesc('payment_date')
            ->get();
    }
}
