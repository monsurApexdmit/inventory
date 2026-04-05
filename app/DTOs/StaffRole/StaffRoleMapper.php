<?php

namespace App\DTOs\StaffRole;

use App\DTOs\BaseMapper;
use App\Models\StaffRole;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting StaffRole model to StaffRoleDTO
 */
class StaffRoleMapper extends BaseMapper
{
    /**
     * Convert StaffRole model to DTO
     */
    public function toDTO(Model $model): StaffRoleDTO
    {
        if (!$model instanceof StaffRole) {
            throw new \InvalidArgumentException('Model must be instance of StaffRole');
        }

        // Get permissions with their names
        $permissions = [];
        if ($model->permissions && $model->permissions->count() > 0) {
            $permissions = $model->permissions->map(function ($rolePermission) {
                return [
                    'id' => $rolePermission->permission_id,
                    'name' => $rolePermission->permission?->name ?? '',
                    'read' => (bool) $rolePermission->read,
                    'write' => (bool) $rolePermission->write,
                    'delete' => (bool) $rolePermission->delete,
                ];
            })->toArray();
        }

        return new StaffRoleDTO(
            id: $model->id,
            companyId: $model->company_id,
            name: $model->name,
            permissions: $permissions,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
