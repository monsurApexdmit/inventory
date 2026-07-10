<?php

namespace App\DTOs\Location;

use App\DTOs\BaseMapper;
use App\Models\Location;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting Location model to LocationDTO
 */
class LocationMapper extends BaseMapper
{
    /**
     * Convert Location model to DTO
     */
    public function toDTO(Model $model): LocationDTO
    {
        if (!$model instanceof Location) {
            throw new \InvalidArgumentException('Model must be instance of Location');
        }

        return new LocationDTO(
            id: $model->id,
            companyId: $model->company_id,
            name: $model->name,
            address: $model->address,
            contactPerson: $model->contact_person,
            isDefault: (bool) $model->is_default,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
