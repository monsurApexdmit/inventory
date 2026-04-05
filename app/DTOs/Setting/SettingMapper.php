<?php

namespace App\DTOs\Setting;

use App\DTOs\BaseMapper;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting Setting model to SettingDTO
 */
class SettingMapper extends BaseMapper
{
    /**
     * Convert Setting model to DTO
     */
    public function toDTO(Model $model): SettingDTO
    {
        if (!$model instanceof Setting) {
            throw new \InvalidArgumentException('Model must be instance of Setting');
        }

        return new SettingDTO(
            id: $model->id,
            companyId: $model->company_id,
            generalSettings: $model->general_settings ? json_decode($model->general_settings, true) : null,
            taxSettings: $model->tax_settings ? json_decode($model->tax_settings, true) : null,
            shippingSettings: $model->shipping_settings ? json_decode($model->shipping_settings, true) : null,
            paymentSettings: $model->payment_settings ? json_decode($model->payment_settings, true) : null,
            businessSettings: $model->business_settings ? json_decode($model->business_settings, true) : null,
            regionalSettings: $model->regional_settings ? json_decode($model->regional_settings, true) : null,
            notificationSettings: $model->notification_settings ? json_decode($model->notification_settings, true) : null,
            storeHours: $model->store_hours ? json_decode($model->store_hours, true) : null,
            logoUrl: $model->logo_url,
            bannerUrl: $model->banner_url,
            uploadedBy: $model->uploaded_by,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
