<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\SaasUser;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Run Phase 2 seeders
        $this->call([
            PermissionSeeder::class,
            PlanSeeder::class,
        ]);

        // Create roles for legacy system
        $adminRole = Role::factory()->withTitle('Admin')->create();
        $userRole = Role::factory()->withTitle('User')->create();

        // Create legacy users
        User::factory()
            ->count(5)
            ->state(['role_id' => $adminRole->id])
            ->create();

        // Create companies
        $companies = Company::factory()
            ->count(10)
            ->create();

        // Create SaaS users
        foreach ($companies as $company) {
            // Create owner
            SaasUser::factory()
                ->owner()
                ->active()
                ->forCompany($company)
                ->create([
                    'email' => "owner_{$company->id}@" . fake()->domainName(),
                ]);

            // Create admin users
            SaasUser::factory()
                ->admin()
                ->active()
                ->count(2)
                ->forCompany($company)
                ->create();

            // Create staff users
            SaasUser::factory()
                ->active()
                ->count(3)
                ->forCompany($company)
                ->create();

            // Create some unverified users
            SaasUser::factory()
                ->unverified()
                ->count(2)
                ->forCompany($company)
                ->create();
        }
    }
}
