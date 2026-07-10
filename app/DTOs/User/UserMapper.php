<?php

namespace App\DTOs\User;

use App\DTOs\BaseMapper;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting User model to UserDTO
 */
class UserMapper extends BaseMapper
{
    /**
     * Convert User model to DTO
     */
    public function toDTO(Model $model): UserDTO
    {
        if (!$model instanceof User) {
            throw new \InvalidArgumentException('Model must be instance of User');
        }

        return new UserDTO(
            id: $model->id,
            username: $model->username ?? $model->email,
            email: $model->email,
            roleId: $model->role_id,
            address: $model->address,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            role: $model->relationLoaded('role') && $model->role ? $this->formatRole($model->role) : null,
        );
    }

    /**
     * Format role relation
     */
    private function formatRole($role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
        ];
    }
}
