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
            // Legacy payloads only carry `write`; it means both create and edit.
            $create = (bool) ($data['create'] ?? $data['write'] ?? false);
            $edit = (bool) ($data['edit'] ?? $data['write'] ?? false);
            $this->model->create([
                'role_id' => $roleId,
                'permission_id' => $data['permission_id'],
                'read' => $data['read'] ?? false,
                'create' => $create,
                'edit' => $edit,
                'write' => $create || $edit,
                'delete' => $data['delete'] ?? false,
            ]);
        }
    }
}
