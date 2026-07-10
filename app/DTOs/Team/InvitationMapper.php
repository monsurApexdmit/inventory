<?php

namespace App\DTOs\Team;

use App\DTOs\BaseMapper;
use App\Models\Invitation;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting Invitation model to InvitationDTO
 */
class InvitationMapper extends BaseMapper
{
    /**
     * Convert Invitation model to DTO
     */
    public function toDTO(Model $model): InvitationDTO
    {
        if (!$model instanceof Invitation) {
            throw new \InvalidArgumentException('Model must be instance of Invitation');
        }

        return new InvitationDTO(
            id: $model->id,
            companyId: $model->company_id,
            email: $model->email,
            fullName: $model->full_name,
            roleId: $model->role_id,
            status: $model->status,
            invitationToken: $model->invitation_token,
            expiresAt: $this->formatTimestamp($model->expires_at),
            acceptedAt: $model->accepted_at ? $this->formatTimestamp($model->accepted_at) : null,
            invitedAt: $this->formatTimestamp($model->invited_at),
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
