<?php

namespace Database\Factories;

use App\Models\BillingContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingContact>
 */
class BillingContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->email(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'zip_code' => fake()->postcode(),
            'country' => 'USA',
            'tax_id' => null,
            'tax_id_type' => null,
            'is_default' => false,
        ];
    }
}
