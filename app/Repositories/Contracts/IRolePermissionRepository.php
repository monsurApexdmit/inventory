<?php

namespace App\Repositories\Contracts;

interface IRolePermissionRepository
{
    public function deleteByRoleId(int $roleId): void;

    public function createMany(int $roleId, array $permissionData): void;
}
