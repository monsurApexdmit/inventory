<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $counter = 0;
        $counter++;

        return [
            'company_id' => Company::factory(),
            'name' => 'Location ' . $counter,
            'address' => '123 Main St, City ' . $counter,
            'contact_person' => 'Contact ' . $counter,
            'is_default' => false,
        ];
    }

    /**
     * Define a default location.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
