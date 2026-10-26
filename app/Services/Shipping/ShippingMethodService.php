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
        $data = $this->normalizePickup($data);
        $method = $this->repository->create(array_merge($data, ['company_id' => $companyId]));
        return $this->format($method);
    }

    public function update(int $id, int $companyId, array $data): array
    {
        $method = $this->repository->findForCompany($id, $companyId);
        $data = $this->normalizePickup($data, $method->type);
        return $this->format($this->repository->update($method, $data));
    }

    public function delete(int $id, int $companyId): void
    {
        $method = $this->repository->findForCompany($id, $companyId);

        // sells/order_shipments store the method as a free string (id or name)
        $keys = array_values(array_unique(array_filter([(string) $method->id, (string) $method->name])));
        $sells = \App\Models\Sell::where('company_id', $companyId)->whereIn('shipping_method', $keys)->count();
        $shipments = \App\Models\OrderShipment::where('company_id', $companyId)->whereIn('shipping_method', $keys)->count();
        if ($sells + $shipments > 0) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(422, "Cannot delete shipping method '{$method->name}': it is used by {$sells} order(s) and {$shipments} shipment(s). Deactivate it instead.");
        }

        $this->repository->delete($method);
    }

    public function toggle(int $id, int $companyId): array
    {
        $method = $this->repository->findForCompany($id, $companyId);
        $method = $this->repository->update($method, ['is_active' => ! $method->is_active]);

        return $this->format($method);
    }

    /** Pickup zones are always free (no delivery fee, no express rate). */
    private function normalizePickup(array $data, ?string $currentType = null): array
    {
        if (($data['type'] ?? $currentType) === 'pickup') {
            $data['type'] = 'pickup';
            $data['price'] = 0;
            $data['express_rate'] = 0;
        }

        return $data;
    }

    /** Active delivery-type method of the company, or null (used to price storefront delivery orders). */
    public function findActiveDelivery(int $companyId, $id): ?ShippingMethod
    {
        if (! is_numeric($id)) {
            return null;
        }

        return ShippingMethod::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('type', 'delivery')->orWhereNull('type')->orWhere('type', ''))
            ->find((int) $id);
    }

    private function format(ShippingMethod $m): array
    {
        return [
            'id'             => $m->id,
            'name'           => $m->name,
            'type'           => $m->type ?: 'delivery',
            'description'    => $m->description,
            'areasCovered'   => $m->areas_covered,
            'price'          => $m->price,
            'expressRate'    => (float) $m->express_rate,
            'estimatedDays'  => $m->estimated_days,
            'icon'           => $m->icon,
            'isActive'       => $m->is_active,
            'sortOrder'      => $m->sort_order,
        ];
    }
}
