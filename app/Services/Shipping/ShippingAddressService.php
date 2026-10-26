<?php

namespace App\Services\Shipping;

use App\DTOs\Shipping\ShippingAddressDTO;
use App\DTOs\Shipping\ShippingAddressMapper;
use App\Models\ShippingAddress;
use App\Repositories\Contracts\IShippingAddressRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ShippingAddressService
{
    private readonly ShippingAddressMapper $mapper;

    public function __construct(private readonly IShippingAddressRepository $repository)
    {
        $this->mapper = new ShippingAddressMapper();
    }

    public function list(int $companyId, array $filters): array
    {
        $addresses = $this->repository->findByCompany($companyId, $filters);
        return array_map(fn ($address) => $this->mapper->toDTO($address), $addresses);
    }

    public function get(int $id, int $companyId): ShippingAddressDTO
    {
        $address = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$address) {
            throw new HttpException(404, 'Shipping address not found');
        }
        return $this->mapper->toDTO($address);
    }

    public function create(int $companyId, array $data): ShippingAddressDTO
    {
        $dbData = $this->mapInputToDb($data);
        $this->assertCustomerBelongs($dbData['customer_id'] ?? null, $companyId);
        $dbData['company_id'] = $companyId;
        $wantsDefault = !empty($dbData['is_default']);
        $dbData['is_default'] = false;
        if (empty($dbData['country'])) {
            $dbData['country'] = 'Bangladesh';
        }
        $address = $this->repository->create($dbData);
        if ($wantsDefault) {
            $this->repository->setDefault($address->id, $address->customer_id);
        }
        return $this->mapper->toDTO($address->fresh('customer'));
    }

    public function update(int $id, int $companyId, array $data): ShippingAddressDTO
    {
        $address = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$address) {
            throw new HttpException(404, 'Shipping address not found');
        }
        $dbData = $this->mapInputToDb($data);
        if (array_key_exists('customer_id', $dbData)) {
            $this->assertCustomerBelongs($dbData['customer_id'], $companyId);
        }
        $wantsDefault = !empty($dbData['is_default']);
        unset($dbData['is_default']);
        $this->repository->update($id, $dbData);
        $fresh = $this->repository->findByIdAndCompany($id, $companyId);
        if (array_key_exists('is_default', $data) && $data['is_default'] !== null) {
            if ($wantsDefault) {
                $this->repository->setDefault($id, $fresh->customer_id);
            } else {
                $fresh->update(['is_default' => $wantsDefault]);
            }
        }
        return $this->get($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $address = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$address) {
            throw new HttpException(404, 'Shipping address not found');
        }
        $this->repository->delete($id);
    }

    public function setDefault(int $id, int $companyId): ShippingAddressDTO
    {
        $address = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$address) {
            throw new HttpException(404, 'Shipping address not found');
        }
        $this->repository->setDefault($id, $address->customer_id);
        return $this->get($id, $companyId);
    }

    private function assertCustomerBelongs(?int $customerId, int $companyId): void
    {
        if ($customerId === null) {
            return;
        }
        $exists = \App\Models\Customer::where('company_id', $companyId)->where('id', $customerId)->exists();
        if (!$exists) {
            throw new HttpException(422, 'Selected customer was not found');
        }
    }

    private function mapInputToDb(array $data): array
    {
        $dbData = [];
        $map = [
            'customerId' => 'customer_id',
            'fullName' => 'full_name',
            'email' => 'email',
            'phone' => 'phone',
            'addressLine1' => 'address_line1',
            'addressLine2' => 'address_line2',
            'city' => 'city',
            'state' => 'state',
            'postalCode' => 'postal_code',
            'country' => 'country',
            'isDefault' => 'is_default',
            'addressType' => 'address_type',
        ];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $data)) {
                $value = $data[$camel];
                if (is_string($value)) {
                    $value = trim($value);
                }
                // Required (NOT NULL) columns keep their old value when blank; optional ones can be cleared.
                if ($value === null || $value === '') {
                    if (in_array($snake, ['full_name', 'phone', 'address_line1', 'city', 'state', 'postal_code', 'country', 'is_default'], true)) {
                        continue;
                    }
                    $value = null;
                }
                $dbData[$snake] = $value;
            }
        }
        return $dbData;
    }
}
