<?php

namespace Database\Factories;

use App\Models\Invitation;
use App\Models\StaffRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->email(),
            'full_name' => fake()->name(),
            'role_id' => null,
            'status' => 'pending',
            'invitation_token' => Str::random(32),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'invited_at' => now(),
        ];
    }

    /**
     * Indicate the invitation is accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
    }

    /**
     * Indicate the invitation is expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
