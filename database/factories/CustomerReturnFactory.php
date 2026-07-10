<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReturn;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerReturnFactory extends Factory
{
    protected $model = CustomerReturn::class;

    public function definition(): array
    {
        $company = Company::factory();
        $customer = Customer::factory();

        return [
            'company_id' => $company,
            'return_number' => 'RET-' . (int)(microtime(true) * 10000),
            'customer_id' => $customer,
            'customer_name' => $this->faker->name,
            'order_id' => null,
            'order_number' => null,
            'total_amount' => $this->faker->randomFloat(2, 10, 500),
            'status' => 'pending',
            'request_date' => now(),
            'processed_date' => null,
            'refund_method' => $this->faker->randomElement(['cash', 'store_credit', 'original_payment']),
            'notes' => $this->faker->sentence,
            'processed_by' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'processed_date' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'processed_date' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'processed_date' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'processed_date' => now(),
        ]);
    }
}
