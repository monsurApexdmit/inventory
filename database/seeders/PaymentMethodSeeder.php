<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = 11;

        $methods = [
            [
                'name'        => 'Cash on Delivery',
                'description' => 'Pay when your order arrives at your doorstep',
                'icon'        => 'banknote',
                'is_active'   => true,
                'sort_order'  => 1,
            ],
            [
                'name'        => 'Bank Transfer',
                'description' => 'Transfer directly to our bank account',
                'icon'        => 'landmark',
                'is_active'   => true,
                'sort_order'  => 2,
            ],
            [
                'name'        => 'Credit / Debit Card',
                'description' => 'Pay securely with Visa, Mastercard or Amex',
                'icon'        => 'credit-card',
                'is_active'   => true,
                'sort_order'  => 3,
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethod::firstOrCreate(
                ['company_id' => $companyId, 'name' => $method['name']],
                $method
            );
        }
    }
}
