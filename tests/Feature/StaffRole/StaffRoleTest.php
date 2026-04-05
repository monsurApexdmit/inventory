<?php

namespace Tests\Feature\StaffRole;

use App\Models\Company;
use App\Models\Permission;
use App\Models\SaasUser;
use App\Models\StaffRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffRoleTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $company = Company::factory()->create();
        $this->companyId = $company->id;

        $owner = SaasUser::factory()
            ->owner()
            ->active()
            ->forCompany($company)
            ->create();

        $this->token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($owner);
    }

    public function test_list_staff_roles(): void
    {
        StaffRole::factory()->create(['company_id' => $this->companyId]);

        $response = $this->getJson('/api/staff-roles', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'name', 'permissions', 'createdAt', 'updatedAt'],
            ],
        ]);
    }

    public function test_get_staff_role(): void
    {
        $role = StaffRole::factory()->create(['company_id' => $this->companyId]);

        $response = $this->getJson("/api/staff-roles/{$role->id}", [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
            ],
        ]);
    }

    public function test_create_staff_role(): void
    {
        $permissions = Permission::limit(2)->get();

        $response = $this->postJson('/api/staff-roles', [
            'name' => 'Manager',
            'permissions' => [
                ['permissionId' => $permissions[0]->id, 'read' => true, 'write' => true, 'delete' => false],
                ['permissionId' => $permissions[1]->id, 'read' => true, 'write' => false, 'delete' => false],
            ],
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('staff_roles', [
            'company_id' => $this->companyId,
            'name' => 'Manager',
        ]);
    }

    public function test_update_staff_role(): void
    {
        $role = StaffRole::factory()->create(['company_id' => $this->companyId]);

        $response = $this->putJson("/api/staff-roles/{$role->id}", [
            'name' => 'Senior Manager',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('staff_roles', [
            'id' => $role->id,
            'name' => 'Senior Manager',
        ]);
    }

    public function test_delete_staff_role(): void
    {
        $role = StaffRole::factory()->create(['company_id' => $this->companyId]);

        $response = $this->deleteJson("/api/staff-roles/{$role->id}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $this->assertSoftDeleted('staff_roles', ['id' => $role->id]);
    }

    public function test_staff_role_not_found(): void
    {
        $response = $this->getJson('/api/staff-roles/999', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(404);
    }

    public function test_create_staff_role_requires_name(): void
    {
        $response = $this->postJson('/api/staff-roles', [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_staff_role_requires_authentication(): void
    {
        $response = $this->getJson('/api/staff-roles');

        $response->assertStatus(401);
    }
}
