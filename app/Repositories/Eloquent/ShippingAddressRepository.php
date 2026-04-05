<?php

namespace App\Repositories\Eloquent;

use App\Models\ShippingAddress;
use App\Repositories\Contracts\IShippingAddressRepository;
use Illuminate\Support\Facades\DB;

class ShippingAddressRepository implements IShippingAddressRepository
{
    public function __construct(private readonly ShippingAddress $model)
    {
    }

    public function findByCompany(int $companyId, array $filters): array
    {
        $query = $this->model->where('company_id', $companyId)->with('customer');

        if (isset($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (isset($filters['address_type'])) {
            $query->where('address_type', $filters['address_type']);
        }

        if (isset($filters['is_default']) && $filters['is_default'] === 'true') {
            $query->where('is_default', true);
        }

        return $query->get()->map(fn($a) => $this->formatAddress($a))->toArray();
    }

    public function findByIdAndCompany(int $id, int $companyId): ?ShippingAddress
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with('customer')
            ->find($id);
    }

    public function create(array $data): ShippingAddress
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ShippingAddress
    {
        $address = $this->model->findOrFail($id);
        $address->fill($data)->save();
        return $address;
    }

    public function delete(int $id): bool
    {
        return (bool) $this->model->findOrFail($id)->delete();
    }

    public function setDefault(int $id, int $customerId): ShippingAddress
    {
        $address = $this->model->findOrFail($id);

        DB::transaction(function () use ($address, $customerId) {
            $this->model->where('customer_id', $customerId)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $address->update(['is_default' => true]);
        });

        return $address->fresh();
    }

    private function formatAddress(ShippingAddress $a): array
    {
        return [
            'id' => $a->id,
            'companyId' => $a->company_id,
            'customerId' => $a->customer_id,
            'fullName' => $a->full_name,
            'phone' => $a->phone,
            'email' => $a->email,
            'addressLine1' => $a->address_line1,
            'addressLine2' => $a->address_line2,
            'city' => $a->city,
            'state' => $a->state,
            'postalCode' => $a->postal_code,
            'country' => $a->country,
            'isDefault' => $a->is_default,
            'addressType' => $a->address_type,
            'createdAt' => $a->created_at,
            'updatedAt' => $a->updated_at,
            'customer' => $a->customer ? [
                'id' => $a->customer->id,
                'name' => $a->customer->name,
            ] : null,
        ];
    }
}
