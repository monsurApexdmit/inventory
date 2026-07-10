<?php

namespace App\Repositories\Eloquent;

use App\Models\RolePermission;
use App\Repositories\Contracts\IRolePermissionRepository;

class RolePermissionRepository implements IRolePermissionRepository
{
    public function __construct(private readonly RolePermission $model)
    {
    }

    public function deleteByRoleId(int $roleId): void
    {
        $this->model->where('role_id', $roleId)->delete();
    }

    public function createMany(int $roleId, array $permissionData): void
    {
        foreach ($permissionData as $data) {
            $this->model->create([
                'role_id' => $roleId,
                'permission_id' => $data['permission_id'],
                'read' => $data['read'] ?? false,
                'write' => $data['write'] ?? false,
                'delete' => $data['delete'] ?? false,
            ]);
        }
    }
}
