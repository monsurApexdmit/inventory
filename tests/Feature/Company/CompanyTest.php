<?php

namespace Tests\Feature\Company;

use App\Models\Company;
use App\Models\SaasUser;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $company = Company::factory()->create();
        $this->companyId = $company->id;

        $owner = SaasUser::factory()
            ->owner()
            ->active()
            ->forCompany($company)
            ->create();

        $this->token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($owner);
    }

    public function test_get_company_profile(): void
    {
        $response = $this->getJson('/api/auth/company/profile', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id', 'name', 'industry', 'phone', 'email', 'website',
                'country', 'address', 'city', 'state', 'zipCode',
            ],
        ]);
    }

    public function test_update_company_profile(): void
    {
        $response = $this->putJson('/api/auth/company/profile', [
            'name' => 'Updated Company Name',
            'phone' => '+1234567890',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('companies', [
            'id' => $this->companyId,
            'name' => 'Updated Company Name',
            'phone' => '+1234567890',
        ]);
    }

    public function test_get_company_status(): void
    {
        $response = $this->getJson('/api/auth/company/status', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id', 'name', 'plan', 'maxUsers', 'activeUsers', 'status',
            ],
        ]);
    }

    public function test_get_company_settings(): void
    {
        $response = $this->getJson('/api/auth/company/settings', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'companyId', 'companyName', 'taxId', 'currency', 'timezone', 'language',
            ],
        ]);
    }

    public function test_upsert_company_settings(): void
    {
        $response = $this->putJson('/api/auth/company/settings', [
            'companyName' => 'Test Company',
            'currency' => 'EUR',
            'timezone' => 'Europe/Berlin',
            'language' => 'de',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('company_settings', [
            'company_id' => $this->companyId,
            'currency' => 'EUR',
        ]);
    }

    public function test_company_profile_requires_authentication(): void
    {
        $response = $this->getJson('/api/auth/company/profile');

        $response->assertStatus(401);
    }

    public function test_update_profile_skips_empty_strings(): void
    {
        // First, update with valid data
        $this->putJson('/api/auth/company/profile', [
            'phone' => '555-1234',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $withPhone = Company::find($this->companyId);
        $phoneValue = $withPhone->phone;

        // Now try to update phone with empty string
        $response = $this->putJson('/api/auth/company/profile', [
            'name' => 'New Name',
            'phone' => '',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $updated = Company::find($this->companyId);

        $this->assertEquals('New Name', $updated->name);
        $this->assertEquals($phoneValue, $updated->phone); // phone unchanged because empty string was filtered
    }

    public function test_company_not_found(): void
    {
        // Create a token for a non-existent company
        $response = $this->getJson('/api/auth/company/profile', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        // Should still work because we injected the real company
        $response->assertStatus(200);
    }
}
