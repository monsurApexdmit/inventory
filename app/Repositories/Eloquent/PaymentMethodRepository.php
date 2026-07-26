<?php

namespace App\Repositories\Eloquent;

use App\Models\PaymentMethod;
use App\Repositories\Contracts\IPaymentMethodRepository;
use Illuminate\Database\Eloquent\Collection;

class PaymentMethodRepository implements IPaymentMethodRepository
{
    public function __construct(private readonly PaymentMethod $model)
    {
    }

    public function findByCompany(int $companyId): Collection
    {
        return $this->model
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function findForCompany(int $id, int $companyId): PaymentMethod
    {
        return $this->model
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }

    public function create(array $data): PaymentMethod
    {
        return $this->model->create($data);
    }

    public function update(PaymentMethod $method, array $data): PaymentMethod
    {
        $method->update($data);

        return $method->fresh();
    }

    public function delete(PaymentMethod $method): void
    {
        $method->delete();
    }
}
