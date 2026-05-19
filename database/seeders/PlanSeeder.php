<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    // ── Module definitions per tier ────────────────────────────────────────────
    // These module names must match exactly what canRead/canWrite/canDelete use
    // in the frontend (app-sidebar.tsx NAV_CONFIG + page-level permission checks).

    private const STARTER_MODULES = [
        // Core always-on
        'Dashboard',
        // Catalog
        'Products', 'Categories', 'Attributes', 'Print Barcode',
        // Sales
        'Orders', 'Customers', 'POS',
        // Inventory (single warehouse only — max_branches=1 enforces this)
        'Inventory',
        // Settings
        'Settings', 'Company Settings', 'Billing Contact',
        'Store', 'Store Locations', 'Shipping Addresses',
        'Payment Methods', 'Shipping Methods',
        // Team (limited to 2 users)
        'Team Members',
        // Billing self-service
        'Subscriptions', 'Billing Plans',
        // Notifications
        'Notifications',
        // Support
        'Support',
    ];

    private const GROWTH_MODULES = [
        // Everything in Starter
        'Dashboard',
        'Products', 'Categories', 'Attributes', 'Coupons', 'Print Barcode',
        'Orders', 'Shipments', 'Customers', 'POS',
        'Inventory', 'Transfers',
        'Customer Returns', 'Vendor Returns',
        'Vendors',
        'Staff', 'Role & Permission',
        'Settings', 'Company Settings', 'Billing Contact',
        'Store', 'Store Locations', 'Shipping Addresses',
        'Payment Methods', 'Shipping Methods',
        'Team Members',
        'Subscriptions', 'Billing Plans',
        'Notifications',
        'Support',
        'Pages',
        'Aura Shop',
    ];

    private const PROFESSIONAL_MODULES = [
        // Everything in Growth
        'Dashboard',
        'Products', 'Categories', 'Attributes', 'Coupons', 'Print Barcode',
        'Orders', 'Shipments', 'Customers', 'POS',
        'Inventory', 'Transfers',
        'Customer Returns', 'Vendor Returns',
        'Vendors',
        'Staff', 'Role & Permission', 'Salary Management',
        'Settings', 'Company Settings', 'Billing Contact',
        'Store', 'Store Locations', 'Shipping Addresses',
        'Payment Methods', 'Shipping Methods',
        'Team Members',
        'Subscriptions', 'Billing Plans',
        'Notifications',
        'Support',
        'Pages',
        'Aura Shop',
        // Pro-only
        'TailorDashboard', 'TailorOrders', 'TailorFabrics',
        'TailorMeasurements', 'TailorDorji', 'TailorPayments', 'TailorReports',
    ];

    private const ENTERPRISE_MODULES = [
        // All modules — no restrictions
        'Dashboard',
        'Products', 'Categories', 'Attributes', 'Coupons', 'Print Barcode',
        'Orders', 'Shipments', 'Customers', 'POS',
        'Inventory', 'Transfers',
        'Customer Returns', 'Vendor Returns',
        'Vendors',
        'Staff', 'Role & Permission', 'Salary Management',
        'Settings', 'Company Settings', 'Billing Contact',
        'Store', 'Store Locations', 'Shipping Addresses',
        'Payment Methods', 'Shipping Methods',
        'Team Members',
        'Subscriptions', 'Billing Plans',
        'Notifications',
        'Support',
        'Pages',
        'Aura Shop',
        'TailorDashboard', 'TailorOrders', 'TailorFabrics',
        'TailorMeasurements', 'TailorDorji', 'TailorPayments', 'TailorReports',
    ];

    public function run(): void
    {
        // ── Starter — $49/mo ──────────────────────────────────────────────────
        SubscriptionPlan::updateOrCreate(
            ['name' => 'Starter'],
            [
                'description'    => 'Perfect for solo entrepreneurs and small stores just getting started.',
                'price'          => 2900, // $29.00 in cents
                'billing_period' => 'monthly',
                'max_users'      => 2,
                'max_products'   => 1000,
                'max_branches'   => 1,
                'features'       => json_encode([
                    'POS & Order Management',
                    'Product Catalog (up to 1,000 products)',
                    'Inventory Tracking (single location)',
                    'Reorder Point & Low Stock Alerts',
                    'Vendor Purchase Orders',
                    'Barcode Printing',
                    'Customer Management',
                    'Payment & Shipping Methods',
                    'Up to 2 Team Members',
                    'Built-in Support Ticket System',
                    'Notifications System',
                    'Email Support',
                ]),
                'modules'        => json_encode(self::STARTER_MODULES),
                'is_active'      => true,
                'is_featured'    => false,
            ]
        );

        // ── Growth — $129/mo ──────────────────────────────────────────────────
        SubscriptionPlan::updateOrCreate(
            ['name' => 'Growth'],
            [
                'description'    => 'For growing businesses that need multi-location support and advanced features.',
                'price'          => 7900, // $79.00 in cents
                'billing_period' => 'monthly',
                'max_users'      => 10,
                'max_products'   => 10000,
                'max_branches'   => 3,
                'features'       => json_encode([
                    'Everything in Starter',
                    'Up to 10,000 Products',
                    'Up to 3 Locations / Warehouses',
                    'Multi-Warehouse Inventory & Stock Transfers',
                    'Customer Returns & Vendor Returns (unique at $79)',
                    'Vendor Management & Purchase Orders',
                    'Coupon & Discount Management',
                    'Shipment Tracking',
                    'Staff Roles & Permissions',
                    'Online Store (Aura Shop)',
                    'CMS Pages',
                    'Up to 10 Team Members',
                    'Priority Chat Support',
                ]),
                'modules'        => json_encode(self::GROWTH_MODULES),
                'is_active'      => true,
                'is_featured'    => true,
            ]
        );

        // ── Professional — $249/mo ────────────────────────────────────────────
        SubscriptionPlan::updateOrCreate(
            ['name' => 'Professional'],
            [
                'description'    => 'For established businesses needing full operational control including tailor shop.',
                'price'          => 14900, // $149.00 in cents
                'billing_period' => 'monthly',
                'max_users'      => 25,
                'max_products'   => 50000,
                'max_branches'   => 10,
                'features'       => json_encode([
                    'Everything in Growth',
                    'Up to 50,000 Products',
                    'Up to 10 Locations / Warehouses',
                    'Up to 25 Team Members',
                    'Salary Management (no competitor at this price)',
                    'Tailor Shop Module — Orders, Fabrics, Measurements, Dorjis, Payments, Reports',
                    '25 Users at $149 vs Zoho\'s 7 Users at same price',
                    'Advanced Reports & Analytics',
                    'Dedicated Support',
                ]),
                'modules'        => json_encode(self::PROFESSIONAL_MODULES),
                'is_active'      => true,
                'is_featured'    => false,
            ]
        );

        // ── Enterprise — $499/mo ──────────────────────────────────────────────
        SubscriptionPlan::updateOrCreate(
            ['name' => 'Enterprise'],
            [
                'description'    => 'Unlimited everything for large retailers and enterprise operations.',
                'price'          => 29900, // $299.00 in cents
                'billing_period' => 'monthly',
                'max_users'      => 999999,
                'max_products'   => 999999,
                'max_branches'   => 999999,
                'features'       => json_encode([
                    'Everything in Professional',
                    'Unlimited Products, Locations & Team Members',
                    'Undercuts Cin7 ($349) by $50 — unlimited users & orders',
                    'White-Label Options',
                    'Custom Integrations & API Access',
                    'Real-time Analytics Dashboard',
                    'Custom Reports & Exports',
                    'Dedicated Account Manager',
                    '24/7 Phone & Email Support',
                    'SLA Guarantee',
                    'Advanced Security Features',
                    'Priority Implementation',
                ]),
                'modules'        => json_encode(self::ENTERPRISE_MODULES),
                'is_active'      => true,
                'is_featured'    => false,
            ]
        );

        // ── Trial — free, same modules as Growth for 10 days ─────────────────
        SubscriptionPlan::updateOrCreate(
            ['name' => 'Trial'],
            [
                'description'    => '10-day free trial. Explore Growth-tier features with limits.',
                'price'          => 0,
                'billing_period' => 'monthly',
                'max_users'      => 2,
                'max_products'   => 50,
                'max_branches'   => 1,
                'features'       => json_encode([
                    'Full Growth-tier feature access',
                    'Up to 50 Products',
                    'Up to 2 Team Members',
                    'Single Location',
                    '10-Day Trial',
                ]),
                'modules'        => json_encode(self::GROWTH_MODULES),
                'is_active'      => true,
                'is_featured'    => false,
            ]
        );
    }
}
