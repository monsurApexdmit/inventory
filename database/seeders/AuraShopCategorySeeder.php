<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class AuraShopCategorySeeder extends Seeder
{
    public function run(): void
    {
        $companyId = 458;

        $categories = [
            'Electronics' => [
                'Smartphones',
                'Laptops & Tablets',
                'Headphones & Audio',
                'Cameras & Photography',
                'Accessories',
            ],
            'Fashion & Apparel' => [
                "Men's Clothing",
                "Women's Clothing",
                "Kids' Wear",
                'Shoes & Footwear',
                'Watches & Jewelry',
            ],
            'Health & Medicine' => [
                'Vitamins & Supplements',
                'First Aid & Safety',
                'Personal Care',
                'Medical Devices',
                'Wellness & Fitness',
            ],
            'Grocery & Food' => [
                'Fresh Fruits & Vegetables',
                'Dairy & Eggs',
                'Bakery & Bread',
                'Snacks & Beverages',
                'Pantry Staples',
            ],
            'Home & Kitchen' => [
                'Furniture',
                'Kitchen Appliances',
                'Home Decor',
                'Bedding & Bath',
                'Lighting',
            ],
            'Beauty & Skincare' => [
                'Skincare',
                'Makeup',
                'Hair Care',
                'Fragrance',
                'Nail Care',
            ],
            'Sports & Outdoors' => [
                'Gym Equipment',
                'Outdoor Gear',
                'Sportswear',
                'Cycling',
                'Camping',
            ],
            'Books & Stationery' => [
                'Fiction',
                'Non-Fiction',
                'Educational',
                'Office Supplies',
                'Art Supplies',
            ],
            'Baby & Kids' => [
                'Baby Food',
                'Diapers & Wipes',
                'Toys & Games',
                "Kids' Clothing",
                'Strollers & Gear',
            ],
            'Beverages' => [
                'Tea',
                'Coffee',
                'Juices & Smoothies',
                'Energy Drinks',
                'Water & Soda',
            ],
            'Tools & Hardware' => [
                'Power Tools',
                'Hand Tools',
                'Electrical',
                'Plumbing',
                'Safety Equipment',
            ],
            'Gaming' => [
                'Consoles',
                'PC Gaming',
                'Gaming Accessories',
                'Video Games',
                'VR & AR',
            ],
        ];

        foreach ($categories as $parentName => $children) {
            // Skip if parent already exists for this company
            $parent = Category::where('company_id', $companyId)
                ->where('category_name', $parentName)
                ->whereNull('parent_id')
                ->first();

            if (!$parent) {
                $parent = Category::create([
                    'company_id'    => $companyId,
                    'category_name' => $parentName,
                    'parent_id'     => null,
                    'status'        => true,
                ]);
                $this->command->info("Created: $parentName");
            } else {
                $this->command->warn("Skipped (exists): $parentName");
            }

            foreach ($children as $childName) {
                // Skip if child already exists under this parent
                $exists = Category::where('company_id', $companyId)
                    ->where('category_name', $childName)
                    ->where('parent_id', $parent->id)
                    ->exists();

                if (!$exists) {
                    Category::create([
                        'company_id'    => $companyId,
                        'category_name' => $childName,
                        'parent_id'     => $parent->id,
                        'status'        => true,
                    ]);
                }
            }
        }

        $this->command->info('AuraShop categories seeded for company_id=458 ✓');
    }
}
