<?php

namespace Database\Factories;

use App\Models\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attribute>
 */
class AttributeFactory extends Factory
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
            'name' => 'Attribute ' . $counter,
            'display_name' => 'Attribute Display ' . $counter,
            'option_type' => 'text',
            'values' => null,
            'description' => 'Test attribute description',
            'is_required' => false,
            'status' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * Define an active attribute.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => true,
        ]);
    }

    /**
     * Define an inactive attribute.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => false,
        ]);
    }
}
