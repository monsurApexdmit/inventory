<?php

namespace App\DTOs\Staff;

use App\DTOs\BaseMapper;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting Staff model to StaffDTO
 */
class StaffMapper extends BaseMapper
{
    /**
     * Convert Staff model to DTO
     */
    public function toDTO(Model $model): StaffDTO
    {
        if (!$model instanceof Staff) {
            throw new \InvalidArgumentException('Model must be instance of Staff');
        }

        return new StaffDTO(
            id: $model->id,
            companyId: $model->company_id,
            userId: $model->user_id,
            name: $model->name,
            email: $model->email,
            contact: $model->contact,
            joiningDate: $model->joining_date,
            role: $model->role,
            status: $model->status,
            published: $model->published ?? true,
            avatar: $model->avatar,
            salary: (float) $model->salary,
            bankAccount: $model->bank_account,
            paymentMethod: $model->payment_method,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            user: $model->relationLoaded('user') && $model->user ? $this->formatUser($model->user) : null,
        );
    }

    /**
     * Format user relation
     */
    private function formatUser($user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username ?? $user->email,
            'email' => $user->email,
        ];
    }
}
