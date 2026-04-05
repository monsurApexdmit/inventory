<?php

namespace Database\Factories;

use App\Models\CompanySettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySettings>
 */
class CompanySettingsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_name' => fake()->company(),
            'tax_id' => 'TAX' . fake()->randomNumber(5),
            'tax_id_type' => 'VAT',
            'tax_rate' => fake()->randomFloat(2, 0, 25),
            'currency' => 'USD',
            'timezone' => 'UTC',
            'language' => 'en',
        ];
    }
}
