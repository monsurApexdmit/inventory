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
    public function toDTO(Model $model, array $stats = []): LocationDTO
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
            type: $model->type ?: 'Shop',
            district: $model->district,
            phone: $model->phone,
            manager: $model->manager,
            sellsInPos: (bool) $model->sells_in_pos,
            shipsOnline: (bool) $model->ships_online,
            isPickupPoint: (bool) ($model->is_pickup_point ?? false),
            stockValue: (float) ($stats['value'] ?? 0),
            skus: (int) ($stats['skus'] ?? 0),
            units: (int) ($stats['units'] ?? 0),
            staffCount: (int) ($stats['staff'] ?? 0),
        );
    }
}
