<?php

namespace App\Services\Location;

use App\DTOs\Location\LocationDTO;
use App\DTOs\Location\LocationMapper;
use App\Repositories\Contracts\ILocationRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LocationService
{
    private readonly LocationMapper $mapper;

    public function __construct(
        private readonly ILocationRepository $locationRepository,
    ) {
        $this->mapper = new LocationMapper();
    }

    public function list(int $companyId): array
    {
        $locations = $this->locationRepository->findByCompany($companyId);
        return array_map(fn ($location) => $this->mapper->toDTO($location), $locations);
    }

    public function get(int $id, int $companyId): LocationDTO
    {
        $location = $this->locationRepository->findByIdAndCompany($id, $companyId);

        if (!$location) {
            throw new HttpException(404, 'Location not found');
        }

        return $this->mapper->toDTO($location);
    }

    public function create(int $companyId, array $data): LocationDTO
    {
        // Convert camelCase to snake_case
        $dbData = [
            'company_id' => $companyId,
            'name' => $data['name'] ?? null,
            'address' => $data['address'] ?? null,
            'contact_person' => $data['contactPerson'] ?? null,
            'is_default' => $data['isDefault'] ?? false,
        ];

        $location = $this->locationRepository->create($dbData);

        return $this->mapper->toDTO($location);
    }

    public function update(int $id, int $companyId, array $data): LocationDTO
    {
        $location = $this->locationRepository->findByIdAndCompany($id, $companyId);

        if (!$location) {
            throw new HttpException(404, 'Location not found');
        }

        // Convert camelCase to snake_case
        $dbData = [];
        if (isset($data['name'])) {
            $dbData['name'] = $data['name'];
        }
        if (isset($data['address'])) {
            $dbData['address'] = $data['address'];
        }
        if (isset($data['contactPerson'])) {
            $dbData['contact_person'] = $data['contactPerson'];
        }
        if (isset($data['isDefault'])) {
            $dbData['is_default'] = $data['isDefault'];
        }

        $this->locationRepository->update($id, $dbData);

        return $this->get($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $location = $this->locationRepository->findByIdAndCompany($id, $companyId);

        if (!$location) {
            throw new HttpException(404, 'Location not found');
        }

        $this->locationRepository->delete($id);
    }
}
