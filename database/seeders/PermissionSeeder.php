<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        'Dashboard',
        'Products',
        'Categories',
        'Attributes',
        'Coupons',
        'Print Barcode',
        'Customers',
        'Orders',
        'Shipments',
        'Vendors',
        'POS',
        'Sells',
        'Inventory',
        'Transfers',
        'Customer Returns',
        'Vendor Returns',
        'Staff',
        'Role & Permission',
        'Salary Management',
        'Settings',
        'Aura Shop',
        'Company Profile',
        'Company Settings',
        'Billing Contact',
        'Team Members',
        'Subscriptions',
        'Billing Plans',
        'Store',
        'Shipping Methods',
        'Payment Methods',
        'Shipping Addresses',
        'Store Locations',
        'Store Wishlist',
        'Pages',
        'International',
        'Notifications',
        'Support',
        // Added in RBAC audit
        'Product Reviews',
        'Checkout',
        'User Management',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }
}
