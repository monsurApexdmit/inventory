<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Every backend permission the Roles matrix maps to (created only if missing). */
    private const PERMISSIONS = [
        'POS', 'Sells', 'Orders', 'Shipments', 'Customers',
        'Products', 'Categories', 'Brands', 'Units', 'Attributes', 'Coupons', 'Print Barcode',
        'Inventory', 'Transfers', 'Vendors', 'Customer Returns', 'Vendor Returns',
        'TailorDorji', 'TailorFabric', 'TailorMeasurements', 'TailorOrders', 'TailorPayments', 'TailorReports', 'TailorShop',
        'Expenses', 'Dues', 'Reports',
        'Staff', 'Role & Permission', 'Salary Management',
        'Settings', 'Company Profile', 'Company Settings', 'Billing Contact', 'International',
        'Subscriptions', 'Billing Plans', 'Team Members',
        'Store', 'Aura Shop', 'Checkout', 'Shipping Methods', 'Payment Methods', 'Shipping Addresses',
        'Store Locations', 'Store Wishlist', 'Product Reviews',
        'Pages', 'Support', 'Notifications', 'Dashboard',
    ];

    public function up(): void
    {
        Schema::table('staff_roles', function (Blueprint $table) {
            $table->string('description')->nullable();
            $table->boolean('locked')->default(false);
            $table->json('sensitive')->nullable();
        });

        Schema::table('role_permissions', function (Blueprint $table) {
            $table->boolean('create')->default(false);
            $table->boolean('edit')->default(false);
        });

        // Legacy single "write" flag becomes both create and edit.
        DB::statement('UPDATE role_permissions SET `create` = `write`, `edit` = `write`');

        DB::table('staff_roles')->whereRaw('LOWER(name) = ?', ['owner'])->update(['locked' => true]);

        $now = now();
        foreach (self::PERMISSIONS as $name) {
            if (!DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('role_permissions', function (Blueprint $table) {
            $table->dropColumn(['create', 'edit']);
        });
        Schema::table('staff_roles', function (Blueprint $table) {
            $table->dropColumn(['description', 'locked', 'sensitive']);
        });
    }
};
