<?php

namespace Database\Seeders;

use App\Models\SaasUser;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'admin@stockflow.io';

        if (SaasUser::where('email', $email)->exists()) {
            $this->command->warn("Super admin {$email} already exists. Skipped.");
            return;
        }

        SaasUser::create([
            'company_id'  => null,
            'full_name'   => 'Platform Admin',
            'email'       => $email,
            'password'    => 'Admin@123456',
            'role'        => 'super_admin',
            'status'      => 'active',
            'joined_date' => now(),
        ]);

        $this->command->info("✓ Super admin created: {$email}");
        $this->command->info("✓ Password: Admin@123456");
        $this->command->warn("  Change this password after first login!");
    }
}
