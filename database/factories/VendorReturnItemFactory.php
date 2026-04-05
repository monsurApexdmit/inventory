<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VendorReturnItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorReturnItemFactory extends Factory
{
    protected $model = VendorReturnItem::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_name' => 'Product Name',
            'variant_id' => ProductVariant::factory(),
            'variant_name' => 'Variant Name',
            'quantity' => $this->faker->numberBetween(1, 10),
            'unit_price' => $this->faker->randomFloat(2, 10, 500),
            'total_price' => $this->faker->randomFloat(2, 50, 5000),
            'unit_cost' => $this->faker->randomFloat(2, 5, 250),
            'reason' => $this->faker->randomElement(['Defective batch', 'Incorrect shipment', 'Over-ordered', 'Quality issue']),
        ];
    }
}
