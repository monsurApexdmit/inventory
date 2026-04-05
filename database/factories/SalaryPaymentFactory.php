<?php

namespace Database\Factories;

use App\Models\Staff;
use App\Models\SalaryPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalaryPaymentFactory extends Factory
{
    protected $model = SalaryPayment::class;
    private static int $counter = 0;

    public static function resetCounter(): void
    {
        self::$counter = 0;
    }

    public function definition(): array
    {
        self::$counter++;
        // Use a random negative offset to avoid collisions
        $randomOffset = mt_rand(100, 500);
        $month = now()->subMonths($randomOffset)->format('Y-m');
        $amount = $this->faker->randomFloat(2, 5000, 50000);

        return [
            'staff_id' => Staff::factory(),
            'month' => $month,
            'amount' => $amount,
            'paid_amount' => 0,
            'status' => 'pending',
            'payment_date' => null,
            'remarks' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn(array $attributes) => [
            'paid_amount' => $attributes['amount'],
            'status' => 'paid',
            'payment_date' => now(),
        ]);
    }

    public function partial(float $paidPercent = 0.5): static
    {
        return $this->state(fn(array $attributes) => [
            'paid_amount' => $attributes['amount'] * $paidPercent,
            'status' => 'partial',
            'payment_date' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn(array $attributes) => [
            'paid_amount' => 0,
            'status' => 'pending',
            'payment_date' => null,
        ]);
    }
}
