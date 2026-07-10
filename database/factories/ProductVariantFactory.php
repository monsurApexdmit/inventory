<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    private static int $counter = 0;

    public function definition(): array
    {
        self::$counter++;

        return [
            'product_id' => Product::factory(),
            'name' => 'Variant ' . self::$counter,
            'attributes' => json_encode(['size' => 'M', 'color' => 'red']),
            'price' => $this->faker->randomFloat(2, 10, 1000),
            'sale_price' => $this->faker->randomFloat(2, 5, 900),
            'cost_price' => $this->faker->randomFloat(2, 1, 500),
            'stock' => $this->faker->numberBetween(0, 1000),
            'sku' => 'VAR-SKU-' . self::$counter,
            'barcode' => 'VAR-BARCODE-' . self::$counter,
        ];
    }
}
