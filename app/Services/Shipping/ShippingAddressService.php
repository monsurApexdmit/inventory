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
        $dbData['company_id'] = $companyId;
        $address = $this->repository->create($dbData);
        return $this->mapper->toDTO($address->fresh('customer'));
    }

    public function update(int $id, int $companyId, array $data): ShippingAddressDTO
    {
        $address = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$address) {
            throw new HttpException(404, 'Shipping address not found');
        }
        $dbData = $this->mapInputToDb($data);
        $this->repository->update($id, $dbData);
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
        if (!$address->customer_id) {
            throw new HttpException(400, 'Address must be associated with a customer');
        }
        $this->repository->setDefault($id, $address->customer_id);
        return $this->get($id, $companyId);
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
            if (isset($data[$camel])) {
                $dbData[$snake] = $data[$camel];
            }
        }
        return $dbData;
    }
}
