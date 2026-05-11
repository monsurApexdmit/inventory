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
                'name'         => 'Cash on Delivery',
                'description'  => 'Pay when your order arrives at your doorstep',
                'icon'         => 'banknote',
                'gateway_type' => 'cod',
                'is_active'    => true,
                'sort_order'   => 1,
            ],
            [
                'name'         => 'SSLCommerz',
                'description'  => 'Pay with bKash, Nagad, Rocket, Cards or Net Banking',
                'icon'         => 'credit-card',
                'gateway_type' => 'sslcommerz',
                'is_active'    => false,
                'sort_order'   => 2,
            ],
            [
                'name'         => 'PortWallet',
                'description'  => 'Pay with Cards or Mobile Banking via PortWallet',
                'icon'         => 'wallet',
                'gateway_type' => 'portwallet',
                'is_active'    => false,
                'sort_order'   => 3,
            ],
            [
                'name'         => 'Stripe',
                'description'  => 'Pay securely with Credit or Debit Card via Stripe',
                'icon'         => 'credit-card',
                'gateway_type' => 'stripe',
                'is_active'    => false,
                'sort_order'   => 4,
            ],
            [
                'name'         => 'PayPal',
                'description'  => 'Pay with your PayPal account',
                'icon'         => 'wallet',
                'gateway_type' => 'paypal',
                'is_active'    => false,
                'sort_order'   => 5,
            ],
            [
                'name'         => 'bKash',
                'description'  => 'Pay directly with your bKash account',
                'icon'         => 'smartphone',
                'gateway_type' => 'bkash',
                'is_active'    => false,
                'sort_order'   => 6,
            ],
            [
                'name'         => 'Nagad',
                'description'  => 'Pay directly with your Nagad account',
                'icon'         => 'smartphone',
                'gateway_type' => 'nagad',
                'is_active'    => false,
                'sort_order'   => 7,
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
