<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    private static int $counter = 0;

    public function definition(): array
    {
        self::$counter++;

        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'name' => 'Vendor ' . self::$counter,
            'email' => 'vendor' . self::$counter . '@example.com',
            'phone' => '+1' . $this->faker->numerify('#########'),
            'address' => $this->faker->address(),
            'logo' => null,
            'uploaded_by' => null,
            'status' => $this->faker->randomElement(['Active', 'Inactive', 'Blocked']),
            'description' => $this->faker->paragraph(),
            'total_paid' => 0,
            'amount_payable' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Active',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Inactive',
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Blocked',
        ]);
    }
}
