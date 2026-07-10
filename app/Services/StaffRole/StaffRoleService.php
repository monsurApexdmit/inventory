<?php

namespace App\Services\StaffRole;

use App\DTOs\StaffRole\StaffRoleDTO;
use App\DTOs\StaffRole\StaffRoleMapper;
use App\Repositories\Contracts\IRolePermissionRepository;
use App\Repositories\Contracts\IStaffRoleRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StaffRoleService
{
    private readonly StaffRoleMapper $mapper;

    public function __construct(
        private readonly IStaffRoleRepository $staffRoleRepository,
        private readonly IRolePermissionRepository $rolePermissionRepository,
    ) {
        $this->mapper = new StaffRoleMapper();
    }

    public function list(int $companyId): array
    {
        $roles = $this->staffRoleRepository->findAllByCompany($companyId);

        return array_map(fn ($role) => $this->mapper->toDTO($role), $roles);
    }

    public function get(int $id, int $companyId): StaffRoleDTO
    {
        $role = $this->staffRoleRepository->findByIdAndCompany($id, $companyId);

        if (!$role) {
            throw new HttpException(404, 'Staff role not found');
        }

        return $this->mapper->toDTO($role);
    }

    public function create(int $companyId, array $data): StaffRoleDTO
    {
        $role = $this->staffRoleRepository->create([
            'company_id' => $companyId,
            'name' => $data['name'],
        ]);

        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $permissions = array_map(fn ($perm) => [
                'permission_id' => $perm['permissionId'],
                'read' => $perm['read'] ?? false,
                'write' => $perm['write'] ?? false,
                'delete' => $perm['delete'] ?? false,
            ], $data['permissions']);
            $this->rolePermissionRepository->createMany($role->id, $permissions);
        }

        return $this->get($role->id, $companyId);
    }

    public function update(int $id, int $companyId, array $data): StaffRoleDTO
    {
        $role = $this->staffRoleRepository->findByIdAndCompany($id, $companyId);

        if (!$role) {
            throw new HttpException(404, 'Staff role not found');
        }

        if (isset($data['name'])) {
            $this->staffRoleRepository->update($id, ['name' => $data['name']]);
        }

        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $this->rolePermissionRepository->deleteByRoleId($id);
            $permissions = array_map(fn ($perm) => [
                'permission_id' => $perm['permissionId'],
                'read' => $perm['read'] ?? false,
                'write' => $perm['write'] ?? false,
                'delete' => $perm['delete'] ?? false,
            ], $data['permissions']);
            $this->rolePermissionRepository->createMany($id, $permissions);
        }

        return $this->get($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $role = $this->staffRoleRepository->findByIdAndCompany($id, $companyId);

        if (!$role) {
            throw new HttpException(404, 'Staff role not found');
        }

        $this->staffRoleRepository->delete($id);
    }
}
