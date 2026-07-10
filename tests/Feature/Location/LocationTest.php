<?php

namespace Tests\Feature\Location;

use App\Models\Company;
use App\Models\Location;
use App\Models\Role;
use App\Models\SaasUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationTest extends TestCase
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

    public function test_list_locations(): void
    {
        Location::factory()->count(3)->create(['company_id' => $this->companyId]);

        $response = $this->getJson('/api/locations', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => ['id', 'name', 'address'],
            ],
        ]);
    }

    public function test_get_location(): void
    {
        $location = Location::factory()->create(['company_id' => $this->companyId]);

        $response = $this->getJson("/api/locations/{$location->id}", [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $location->id,
                'name' => $location->name,
            ],
        ]);
    }

    public function test_create_location(): void
    {
        $response = $this->postJson('/api/locations', [
            'name' => 'Main Warehouse',
            'address' => '123 Main St',
            'contactPerson' => 'John Doe',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'data' => [
                'name' => 'Main Warehouse',
                'address' => '123 Main St',
            ],
        ]);

        $this->assertDatabaseHas('locations', [
            'company_id' => $this->companyId,
            'name' => 'Main Warehouse',
        ]);
    }

    public function test_update_location(): void
    {
        $location = Location::factory()->create(['company_id' => $this->companyId]);

        $response = $this->putJson("/api/locations/{$location->id}", [
            'name' => 'Updated Warehouse',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'name' => 'Updated Warehouse',
            ],
        ]);

        $this->assertDatabaseHas('locations', [
            'id' => $location->id,
            'name' => 'Updated Warehouse',
        ]);
    }

    public function test_delete_location(): void
    {
        $location = Location::factory()->create(['company_id' => $this->companyId]);

        $response = $this->deleteJson("/api/locations/{$location->id}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'message' => 'Location deleted successfully',
            ],
        ]);

        $this->assertSoftDeleted('locations', ['id' => $location->id]);
    }

    public function test_get_nonexistent_location_returns_404(): void
    {
        $response = $this->getJson('/api/locations/99999', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(404);
    }

    public function test_create_location_requires_authentication(): void
    {
        $response = $this->postJson('/api/locations', [
            'name' => 'Main Warehouse',
        ]);

        $response->assertStatus(401);
    }

    public function test_create_location_name_required(): void
    {
        $response = $this->postJson('/api/locations', [
            'address' => '123 Main St',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
    }
}
