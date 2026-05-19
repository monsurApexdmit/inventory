<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    private static int $counter = 0;

    public function definition(): array
    {
        self::$counter++;

        return [
            'company_id' => Company::factory(),
            'category_id' => Category::factory(),
            'vendor_id' => User::factory(),
            'location_id' => Location::factory(),
            'name' => 'Product ' . self::$counter,
            'description' => $this->faker->paragraph(),
            'price' => $this->faker->randomFloat(2, 10, 1000),
            'sale_price' => $this->faker->randomFloat(2, 5, 900),
            'cost_price' => $this->faker->randomFloat(2, 1, 500),
            'stock' => $this->faker->numberBetween(0, 1000),
            'sku' => 'SKU-' . self::$counter,
            'barcode' => 'BARCODE-' . self::$counter,
            'slug' => 'product-' . self::$counter . '-' . uniqid(),
            'published' => $this->faker->boolean(),
            'receipt_number' => null,
            'image' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'published' => true,
        ]);
    }

    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'published' => false,
        ]);
    }
}
