<?php

namespace Tests\Feature\Staff;

use App\Models\Company;
use App\Models\Role;
use App\Models\SaasUser;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Role::factory()->create();

        $company = Company::factory()->create();
        $this->companyId = $company->id;

        $owner = SaasUser::factory()
            ->owner()
            ->active()
            ->forCompany($company)
            ->create();

        $this->token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($owner);
    }

    public function test_list_staff(): void
    {
        Staff::factory()->count(3)->create(['company_id' => $this->companyId]);

        $response = $this->getJson('/api/staff', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'data' => [
                    '*' => ['id', 'name', 'email', 'status'],
                ],
            ],
        ]);
    }

    public function test_get_staff(): void
    {
        $staff = Staff::factory()->create(['company_id' => $this->companyId]);

        $response = $this->getJson("/api/staff/{$staff->id}", [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
            ],
        ]);
    }

    public function test_create_staff(): void
    {
        $response = $this->postJson('/api/staff', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'contact' => '+1234567890',
            'joiningDate' => '2026-03-31',
            'role' => 'Developer',
            'status' => 'Active',
            'salary' => 5000,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'data' => [
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ],
        ]);

        $this->assertDatabaseHas('staff', [
            'company_id' => $this->companyId,
            'email' => 'john@example.com',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
        ]);
    }

    public function test_update_staff(): void
    {
        $staff = Staff::factory()->create(['company_id' => $this->companyId]);

        $response = $this->putJson("/api/staff/{$staff->id}", [
            'name' => 'Updated Name',
            'status' => 'Inactive',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('staff', [
            'id' => $staff->id,
            'name' => 'Updated Name',
            'status' => 'Inactive',
        ]);
    }

    public function test_delete_staff(): void
    {
        $staff = Staff::factory()->create(['company_id' => $this->companyId]);

        $response = $this->deleteJson("/api/staff/{$staff->id}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(204);
        $this->assertSoftDeleted('staff', ['id' => $staff->id]);
    }

    public function test_create_staff_with_duplicate_email(): void
    {
        Staff::factory()->create([
            'company_id' => $this->companyId,
            'email' => 'existing@example.com',
        ]);

        $response = $this->postJson('/api/staff', [
            'name' => 'New Staff',
            'email' => 'existing@example.com',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_staff_requires_authentication(): void
    {
        $response = $this->getJson('/api/staff');

        $response->assertStatus(401);
    }

    public function test_staff_not_found(): void
    {
        $response = $this->getJson('/api/staff/999', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(404);
    }

    public function test_create_staff_requires_name(): void
    {
        $response = $this->postJson('/api/staff', [
            'email' => 'test@example.com',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }
}
