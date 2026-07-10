<?php

namespace App\DTOs\Attribute;

use App\DTOs\BaseMapper;
use App\Models\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting Attribute model to AttributeDTO
 */
class AttributeMapper extends BaseMapper
{
    /**
     * Convert Attribute model to DTO
     */
    public function toDTO(Model $model): AttributeDTO
    {
        if (!$model instanceof Attribute) {
            throw new \InvalidArgumentException('Model must be instance of Attribute');
        }

        return new AttributeDTO(
            id: $model->id,
            companyId: $model->company_id,
            name: $model->name,
            displayName: $model->display_name,
            optionType: $model->option_type,
            values: $model->values,
            description: $model->description,
            isRequired: (bool) $model->is_required,
            status: (bool) $model->status,
            sortOrder: (int) $model->sort_order,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
