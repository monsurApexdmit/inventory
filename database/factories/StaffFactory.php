<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaffFactory extends Factory
{
    protected $model = Staff::class;
    private static int $counter = 0;

    public function definition(): array
    {
        self::$counter++;

        return [
            'company_id' => Company::factory(),
            'user_id' => null,
            'name' => $this->faker->name,
            'email' => 'staff' . self::$counter . '@example.com',
            'contact' => $this->faker->phoneNumber,
            'joining_date' => $this->faker->dateTimeThisYear()->format('Y-m-d'),
            'role' => 'Staff',
            'status' => 'Active',
            'published' => true,
            'avatar' => null,
            'uploaded_by' => null,
            'salary' => $this->faker->randomFloat(2, 5000, 50000),
            'bank_account' => $this->faker->bankAccountNumber,
            'payment_method' => $this->faker->randomElement(['Bank Transfer', 'Cash', 'Check']),
        ];
    }
}
