<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'description' => $this->faker->sentence(),
            'price' => $this->faker->randomElement([2900, 4900, 9900]),
            'billing_period' => 'monthly',
            'max_users' => $this->faker->randomElement([3, 10, 50]),
            'max_products' => $this->faker->randomElement([500, 2000, 10000]),
            'max_branches' => $this->faker->randomElement([1, 3, 10]),
            'features' => json_encode(['Inventory Management', 'POS System']),
            'is_active' => true,
            'is_featured' => false,
        ];
    }
}
