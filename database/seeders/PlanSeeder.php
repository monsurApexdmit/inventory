<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // Trial Plan - Always free, feature limited
        SubscriptionPlan::firstOrCreate(
            ['name' => 'Trial'],
            [
                'description' => '10-day free trial to explore all features',
                'price' => 0,
                'billing_period' => 'monthly',
                'max_users' => 10,
                'max_products' => 1000,
                'max_branches' => 1,
                'features' => json_encode([
                    'Basic POS System',
                    'Inventory Management',
                    'Basic Reporting',
                    'Email Support',
                    'Up to 10 Team Members',
                ]),
                'is_active' => true,
                'is_featured' => false,
            ]
        );

        // Starter Plan - $49/month, for solo entrepreneurs and small online stores
        SubscriptionPlan::firstOrCreate(
            ['name' => 'Starter'],
            [
                'description' => 'Perfect for solo entrepreneurs and small online stores',
                'price' => 4900, // $49.00 in cents
                'billing_period' => 'monthly',
                'max_users' => 2,
                'max_products' => 1000,
                'max_branches' => 1,
                'features' => json_encode([
                    'Basic POS System',
                    'Inventory Management',
                    'Order Management',
                    'Basic Analytics',
                    'Email Support',
                    'Mobile App Access',
                    'Up to 2 Team Members',
                    'Single Warehouse',
                ]),
                'is_active' => true,
                'is_featured' => false,
            ]
        );

        // Professional Plan - $149/month, for growing e-commerce businesses
        SubscriptionPlan::firstOrCreate(
            ['name' => 'Professional'],
            [
                'description' => 'For growing e-commerce businesses and multi-location operations',
                'price' => 14900, // $149.00 in cents
                'billing_period' => 'monthly',
                'max_users' => 5,
                'max_products' => 10000,
                'max_branches' => 5,
                'features' => json_encode([
                    'Advanced POS System',
                    'Multi-Warehouse Inventory',
                    'Advanced Order Management',
                    'Customer Returns Management',
                    'Vendor Management',
                    'Advanced Analytics & Reports',
                    'Chat Support',
                    'Priority Response Times',
                    'Mobile App Access',
                    'Up to 5 Team Members',
                    'Multiple Warehouses (up to 5)',
                    'API Access',
                ]),
                'is_active' => true,
                'is_featured' => true,
            ]
        );

        // Enterprise Plan - $499/month, unlimited features
        SubscriptionPlan::firstOrCreate(
            ['name' => 'Enterprise'],
            [
                'description' => 'For large retailers and enterprise operations with unlimited everything',
                'price' => 49900, // $499.00 in cents
                'billing_period' => 'monthly',
                'max_users' => 999999, // unlimited (represented as very large number)
                'max_products' => 999999, // unlimited
                'max_branches' => 999999, // unlimited
                'features' => json_encode([
                    'Premium POS System',
                    'Unlimited Warehouses & Locations',
                    'Unlimited Team Members',
                    'Unlimited Products',
                    'Advanced Multi-Vendor Management',
                    'Custom Integrations',
                    'Advanced API Access',
                    'Real-time Analytics Dashboard',
                    'Custom Reports & Exports',
                    'Advanced Order Management',
                    'Customer Returns Management',
                    'Dedicated Account Manager',
                    '24/7 Phone & Email Support',
                    'Priority Implementation',
                    'White-Label Options',
                    'Advanced Security Features',
                ]),
                'is_active' => true,
                'is_featured' => true,
            ]
        );
    }
}
