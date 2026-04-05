<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        SubscriptionPlan::firstOrCreate(
            ['name' => 'Trial'],
            [
                'description' => '10-day free trial',
                'price' => 0,
                'billing_period' => 'monthly',
                'max_users' => 10,
                'max_products' => 1000,
                'max_branches' => 1,
                'features' => json_encode(['basic_support', 'limited_api_calls']),
                'is_active' => true,
                'is_featured' => false,
            ]
        );

        SubscriptionPlan::firstOrCreate(
            ['name' => 'Basic'],
            [
                'description' => 'Perfect for small teams',
                'price' => 2900, // $29.00 in cents
                'billing_period' => 'monthly',
                'max_users' => 50,
                'max_products' => 5000,
                'max_branches' => 3,
                'features' => json_encode(['email_support', 'standard_api_calls', 'basic_analytics']),
                'is_active' => true,
                'is_featured' => true,
            ]
        );

        SubscriptionPlan::firstOrCreate(
            ['name' => 'Professional'],
            [
                'description' => 'For growing businesses',
                'price' => 5900, // $59.00 in cents
                'billing_period' => 'monthly',
                'max_users' => 200,
                'max_products' => 50000,
                'max_branches' => 10,
                'features' => json_encode(['priority_support', 'unlimited_api_calls', 'advanced_analytics', 'custom_reports']),
                'is_active' => true,
                'is_featured' => true,
            ]
        );
    }
}
