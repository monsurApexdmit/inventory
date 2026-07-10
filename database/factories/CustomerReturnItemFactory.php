<?php

namespace Database\Factories;

use App\Models\CustomerReturnItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerReturnItemFactory extends Factory
{
    protected $model = CustomerReturnItem::class;

    public function definition(): array
    {
        $product = Product::factory();
        $variant = ProductVariant::factory();

        return [
            'product_id' => $product,
            'product_name' => $this->faker->word,
            'variant_id' => $variant,
            'variant_name' => $this->faker->word,
            'quantity' => $this->faker->numberBetween(1, 5),
            'price' => $this->faker->randomFloat(2, 10, 100),
            'reason' => $this->faker->randomElement(['Defective', 'Wrong size', 'Changed mind', 'Damaged in shipping']),
        ];
    }
}
