<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\OrderShipment;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderShipmentFactory extends Factory
{
    protected $model = OrderShipment::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'sell_id' => null, // Will be set in tests
            'tracking_number' => 'TRACK-' . strtoupper($this->faker->bothify('????????')),
            'carrier' => $this->faker->randomElement(['DHL', 'FedEx', 'UPS', 'Standard Mail']),
            'shipping_method' => $this->faker->randomElement(['Express', 'Standard', 'Overnight']),
            'status' => 'pending',
            'shipped_at' => now(),
            'estimated_delivery' => now()->addDays(5),
            'delivered_at' => null,
            'shipping_cost' => $this->faker->randomFloat(2, 5, 50),
            'weight' => $this->faker->randomFloat(2, 0.5, 20),
            'dimensions' => $this->faker->numerify('##x##x##'),
            'notes' => $this->faker->sentence,
        ];
    }

    public function delivered(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);
    }
}
