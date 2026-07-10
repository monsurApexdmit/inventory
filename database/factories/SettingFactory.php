<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'general_settings' => [],
            'tax_settings' => [],
            'shipping_settings' => [],
            'payment_settings' => [],
            'business_settings' => [],
            'regional_settings' => [],
            'notification_settings' => [],
            'store_hours' => [],
            'logo_url' => null,
            'banner_url' => null,
            'uploaded_by' => null,
        ];
    }
}
