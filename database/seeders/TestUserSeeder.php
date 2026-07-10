<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Location;
use App\Models\SaasUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TestUserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed test user for inventory demos
     */
    public function run(): void
    {
        // Create a test company if needed
        $company = Company::firstOrCreate(
            ['name' => 'Startup Inc'],
            ['status' => 'active']
        );

        // Create test user
        $user = SaasUser::firstOrCreate(
            ['company_id' => $company->id, 'email' => 'jane.smith@startup.io'],
            [
                'full_name' => 'Jane Smith',
                'password' => bcrypt('StartupPass123!'),
                'role' => 'admin',
                'status' => 'active',
            ]
        );

        echo "✓ Test user created: jane.smith@startup.io\n";
        echo "✓ Password: StartupPass123!\n";
        echo "✓ Company ID: " . $company->id . "\n";
    }
}
