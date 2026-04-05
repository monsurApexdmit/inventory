<?php

namespace App\DTOs\Company;

use App\DTOs\BaseMapper;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting Company model to CompanyDTO
 */
class CompanyMapper extends BaseMapper
{
    /**
     * Convert Company model to DTO
     */
    public function toDTO(Model $model): CompanyDTO
    {
        if (!$model instanceof Company) {
            throw new \InvalidArgumentException('Model must be instance of Company');
        }

        return new CompanyDTO(
            id: $model->id,
            name: $model->name,
            industry: $model->industry,
            phone: $model->phone,
            email: $model->email,
            website: $model->website,
            country: $model->country,
            address: $model->address,
            businessType: $model->business_type,
            taxId: $model->tax_id,
            currency: $model->currency,
            timezone: $model->timezone,
            language: $model->language,
            status: $model->status ?? 'active',
            logo: $model->logo,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
