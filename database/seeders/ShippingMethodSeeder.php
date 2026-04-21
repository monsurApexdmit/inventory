<?php

namespace Database\Seeders;

use App\Models\ShippingMethod;
use Illuminate\Database\Seeder;

class ShippingMethodSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = 11;

        $methods = [
            [
                'name'           => 'Standard Shipping',
                'description'    => 'Reliable delivery at the best price',
                'price'          => 5.99,
                'estimated_days' => '5–7 Business Days',
                'icon'           => 'package',
                'is_active'      => true,
                'sort_order'     => 1,
            ],
            [
                'name'           => 'Express Shipping',
                'description'    => 'Faster delivery for when you need it sooner',
                'price'          => 12.99,
                'estimated_days' => '2–3 Business Days',
                'icon'           => 'truck',
                'is_active'      => true,
                'sort_order'     => 2,
            ],
            [
                'name'           => 'Overnight Shipping',
                'description'    => 'Get it tomorrow — guaranteed next-day delivery',
                'price'          => 24.99,
                'estimated_days' => 'Next Business Day',
                'icon'           => 'zap',
                'is_active'      => true,
                'sort_order'     => 3,
            ],
        ];

        foreach ($methods as $method) {
            ShippingMethod::firstOrCreate(
                ['company_id' => $companyId, 'name' => $method['name']],
                $method
            );
        }
    }
}
