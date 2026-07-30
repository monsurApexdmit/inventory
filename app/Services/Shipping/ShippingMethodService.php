<?php

namespace App\Services\Shipping;

use App\Models\ShippingMethod;
use App\Repositories\Contracts\IShippingMethodRepository;

class ShippingMethodService
{
    public function __construct(private readonly IShippingMethodRepository $repository)
    {
    }

    public function list(int $companyId): array
    {
        return $this->repository->findByCompany($companyId)
            ->map(fn (ShippingMethod $m) => $this->format($m))
            ->all();
    }

    public function create(int $companyId, array $data): array
    {
        $method = $this->repository->create(array_merge($data, ['company_id' => $companyId]));

        return $this->format($method);
    }

    public function update(int $id, int $companyId, array $data): array
    {
        $method = $this->repository->findForCompany($id, $companyId);

        return $this->format($this->repository->update($method, $data));
    }

    public function delete(int $id, int $companyId): void
    {
        $method = $this->repository->findForCompany($id, $companyId);
        $this->repository->delete($method);
    }

    public function toggle(int $id, int $companyId): array
    {
        $method = $this->repository->findForCompany($id, $companyId);
        $method = $this->repository->update($method, ['is_active' => ! $method->is_active]);

        return $this->format($method);
    }

    private function format(ShippingMethod $m): array
    {
        return [
            'id'             => $m->id,
            'name'           => $m->name,
            'description'    => $m->description,
            'price'          => $m->price,
            'estimatedDays'  => $m->estimated_days,
            'icon'           => $m->icon,
            'isActive'       => $m->is_active,
            'sortOrder'      => $m->sort_order,
        ];
    }
}
