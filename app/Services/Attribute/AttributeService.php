<?php

namespace App\Services\Attribute;

use App\DTOs\Attribute\AttributeDTO;
use App\DTOs\Attribute\AttributeMapper;
use App\Repositories\Contracts\IAttributeRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AttributeService
{
    private readonly AttributeMapper $mapper;

    public function __construct(
        private readonly IAttributeRepository $attributeRepository,
    ) {
        $this->mapper = new AttributeMapper();
    }

    public function list(int $companyId, array $filters = []): array
    {
        $paginated = $this->attributeRepository->findByCompany($companyId, $filters);
        $data = array_map(fn ($attribute) => $this->mapper->toDTO($attribute), $paginated->items());
        return [
            'data' => $data,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    public function simple(int $companyId): array
    {
        $attributes = $this->attributeRepository->findSimpleByCompany($companyId);
        return array_map(fn ($attribute) => $this->mapper->toDTO($attribute), $attributes);
    }

    public function stats(int $companyId): array
    {
        return $this->attributeRepository->getStatsByCompany($companyId);
    }

    public function get(int $id, int $companyId): AttributeDTO
    {
        $attribute = $this->attributeRepository->findByIdAndCompany($id, $companyId);

        if (!$attribute) {
            throw new HttpException(404, 'Attribute not found');
        }

        return $this->mapper->toDTO($attribute);
    }

    public function create(int $companyId, array $data): AttributeDTO
    {
        // Convert camelCase to snake_case
        $dbData = [
            'company_id' => $companyId,
            'name' => $data['name'] ?? null,
            'display_name' => $data['displayName'] ?? null,
            'option_type' => $data['optionType'] ?? 'text',
            'values' => $data['values'] ?? null,
            'description' => $data['description'] ?? null,
            'is_required' => $data['isRequired'] ?? false,
            'status' => $data['status'] ?? true,
            'sort_order' => $data['sortOrder'] ?? 0,
        ];

        // Check for duplicate name
        if ($this->attributeRepository->existsByNameAndCompany($dbData['name'], $companyId)) {
            throw new HttpException(409, 'Attribute with this name already exists');
        }

        $attribute = $this->attributeRepository->create($dbData);

        return $this->mapper->toDTO($attribute);
    }

    public function update(int $id, int $companyId, array $data): AttributeDTO
    {
        $attribute = $this->attributeRepository->findByIdAndCompany($id, $companyId);

        if (!$attribute) {
            throw new HttpException(404, 'Attribute not found');
        }

        // Convert camelCase to snake_case
        $dbData = [];
        if (isset($data['name'])) {
            $dbData['name'] = $data['name'];
        }
        if (isset($data['displayName'])) {
            $dbData['display_name'] = $data['displayName'];
        }
        if (isset($data['optionType'])) {
            $dbData['option_type'] = $data['optionType'];
        }
        if (isset($data['values'])) {
            $dbData['values'] = $data['values'];
        }
        if (isset($data['description'])) {
            $dbData['description'] = $data['description'];
        }
        if (isset($data['isRequired'])) {
            $dbData['is_required'] = $data['isRequired'];
        }
        if (isset($data['status'])) {
            $dbData['status'] = $data['status'];
        }
        if (isset($data['sortOrder'])) {
            $dbData['sort_order'] = $data['sortOrder'];
        }

        // Check for duplicate name (excluding self)
        if (!empty($dbData['name']) && $dbData['name'] !== $attribute->name) {
            if ($this->attributeRepository->existsByNameAndCompany($dbData['name'], $companyId, $id)) {
                throw new HttpException(409, 'Attribute with this name already exists');
            }
        }

        $this->attributeRepository->update($id, $dbData);

        return $this->get($id, $companyId);
    }

    public function toggleStatus(int $id, int $companyId): AttributeDTO
    {
        $attribute = $this->attributeRepository->findByIdAndCompany($id, $companyId);

        if (!$attribute) {
            throw new HttpException(404, 'Attribute not found');
        }

        $this->attributeRepository->update($id, [
            'status' => !$attribute->status,
        ]);

        return $this->get($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $attribute = $this->attributeRepository->findByIdAndCompany($id, $companyId);

        if (!$attribute) {
            throw new HttpException(404, 'Attribute not found');
        }

        $this->attributeRepository->delete($id);
    }

    public function bulkDelete(array $ids, int $companyId): int
    {
        return $this->attributeRepository->bulkDelete($ids, $companyId);
    }
}
