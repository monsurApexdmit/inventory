<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;
    private static int $counter = 0;

    public function definition(): array
    {
        self::$counter++;
        $counter = self::$counter;
        $company = Company::factory();
        $user = User::factory();

        return [
            'company_id' => $company,
            'user_id' => $user,
            'name' => "Customer {$counter}",
            'email' => "customer{$counter}@example.com",
            'phone' => $this->faker->phoneNumber,
            'address' => $this->faker->streetAddress,
            'city' => $this->faker->city,
            'state' => $this->faker->stateAbbr,
            'zip_code' => $this->faker->postcode,
            'country' => $this->faker->countryCode,
            'customer_type' => $this->faker->randomElement(['retail', 'wholesale']),
            'status' => 'active',
            'notes' => $this->faker->sentence,
            'store_credit' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    public function retail(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_type' => 'retail',
        ]);
    }

    public function wholesale(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_type' => 'wholesale',
        ]);
    }
}
