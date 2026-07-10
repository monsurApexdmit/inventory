<?php

namespace App\DTOs\Notification;

use App\DTOs\BaseMapper;
use App\Models\Notification;

class NotificationMapper extends BaseMapper
{
    public function toDTO($model): NotificationDTO
    {
        if (!$model instanceof Notification) {
            throw new \InvalidArgumentException('Model must be instance of Notification');
        }

        return new NotificationDTO(
            id:        $model->id,
            companyId: $model->company_id,
            type:      $model->type,
            title:     $model->title,
            message:   $model->message,
            data:      $model->data,
            isRead:    $model->isRead(),
            readAt:    $this->formatTimestamp($model->read_at),
            createdAt: $this->formatTimestamp($model->created_at),
        );
    }
}
