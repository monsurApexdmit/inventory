<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'amount' => fake()->randomNumber(4),
            'status' => 'completed',
            'payment_method' => fake()->randomElement(['credit_card', 'bank_transfer']),
            'payment_date' => now(),
            'invoice_number' => 'INV' . fake()->randomNumber(8),
            'invoice_url' => null,
            'stripe_payment_id' => null,
            'description' => 'Monthly subscription payment',
        ];
    }
}
