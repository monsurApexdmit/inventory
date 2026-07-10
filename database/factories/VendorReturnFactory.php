<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Vendor;
use App\Models\VendorReturn;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorReturnFactory extends Factory
{
    protected $model = VendorReturn::class;

    private static int $counter = 0;

    public function definition(): array
    {
        self::$counter++;

        return [
            'company_id' => Company::factory(),
            'return_number' => 'VRT-' . microtime(true) * 10000,
            'vendor_id' => Vendor::factory(),
            'vendor_name' => 'Vendor Name',
            'total_amount' => $this->faker->randomFloat(2, 100, 5000),
            'status' => $this->faker->randomElement(['pending', 'shipped', 'received_by_vendor', 'completed']),
            'return_date' => $this->faker->dateTime(),
            'completed_date' => null,
            'credit_type' => $this->faker->randomElement(['refund', 'credit_note', 'replacement']),
            'notes' => $this->faker->paragraph(),
            'created_by' => 'admin',
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'completed_date' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_date' => now(),
        ]);
    }
}
