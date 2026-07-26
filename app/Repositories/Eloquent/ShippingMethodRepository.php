<?php

namespace App\Repositories\Eloquent;

use App\Models\ShippingMethod;
use App\Repositories\Contracts\IShippingMethodRepository;
use Illuminate\Database\Eloquent\Collection;

class ShippingMethodRepository implements IShippingMethodRepository
{
    public function __construct(private readonly ShippingMethod $model)
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

    public function findForCompany(int $id, int $companyId): ShippingMethod
    {
        return $this->model
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }

    public function create(array $data): ShippingMethod
    {
        return $this->model->create($data);
    }

    public function update(ShippingMethod $method, array $data): ShippingMethod
    {
        $method->update($data);

        return $method->fresh();
    }

    public function delete(ShippingMethod $method): void
    {
        $method->delete();
    }
}
