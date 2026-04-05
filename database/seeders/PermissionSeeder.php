<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'Dashboard',
            'Products',
            'Categories',
            'Attributes',
            'Coupons',
            'Customers',
            'Orders',
            'POS',
            'Sells',
            'Staff',
            'Settings',
            'International',
            'Store',
            'Pages',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }
}
