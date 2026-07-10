<?php

namespace Tests\Feature\Attribute;

use App\Models\Attribute;
use App\Models\Company;
use App\Models\Role;
use App\Models\SaasUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttributeTest extends TestCase
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

    public function test_list_attributes(): void
    {
        Attribute::factory()->count(5)->create(['company_id' => $this->companyId]);

        $response = $this->getJson('/api/attributes', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'data' => [
                    '*' => ['id', 'name', 'displayName', 'optionType'],
                ],
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
        ]);
    }

    public function test_get_simple_attributes(): void
    {
        Attribute::factory()->count(3)->create(['company_id' => $this->companyId, 'status' => true]);

        $response = $this->getJson('/api/attributes/simple', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'name', 'displayName'],
            ],
        ]);
    }

    public function test_get_attribute_stats(): void
    {
        Attribute::factory()->count(4)->create(['company_id' => $this->companyId, 'status' => true]);
        Attribute::factory()->count(2)->create(['company_id' => $this->companyId, 'status' => false]);

        $response = $this->getJson('/api/attributes/stats', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'total' => 6,
                'active' => 4,
                'inactive' => 2,
            ],
        ]);
    }

    public function test_get_attribute(): void
    {
        $attribute = Attribute::factory()->create(['company_id' => $this->companyId]);

        $response = $this->getJson("/api/attributes/{$attribute->id}", [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $attribute->id,
                'name' => $attribute->name,
            ],
        ]);
    }

    public function test_create_attribute(): void
    {
        $response = $this->postJson('/api/attributes', [
            'name' => 'Size',
            'displayName' => 'Product Size',
            'optionType' => 'dropdown',
            'values' => 'S,M,L,XL',
            'isRequired' => true,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'data' => [
                'name' => 'Size',
                'displayName' => 'Product Size',
                'optionType' => 'dropdown',
            ],
        ]);

        $this->assertDatabaseHas('attributes', [
            'company_id' => $this->companyId,
            'name' => 'Size',
        ]);
    }

    public function test_create_duplicate_attribute_returns_409(): void
    {
        Attribute::factory()->create([
            'company_id' => $this->companyId,
            'name' => 'Size',
        ]);

        $response = $this->postJson('/api/attributes', [
            'name' => 'Size',
            'displayName' => 'Product Size',
            'optionType' => 'dropdown',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(409);
    }

    public function test_update_attribute(): void
    {
        $attribute = Attribute::factory()->create(['company_id' => $this->companyId]);

        $response = $this->putJson("/api/attributes/{$attribute->id}", [
            'displayName' => 'Updated Display Name',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'displayName' => 'Updated Display Name',
            ],
        ]);
    }

    public function test_toggle_attribute_status(): void
    {
        $attribute = Attribute::factory()->create(['company_id' => $this->companyId, 'status' => true]);

        $response = $this->patchJson("/api/attributes/{$attribute->id}/toggle-status", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'status' => false,
            ],
        ]);
    }

    public function test_delete_attribute(): void
    {
        $attribute = Attribute::factory()->create(['company_id' => $this->companyId]);

        $response = $this->deleteJson("/api/attributes/{$attribute->id}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'message' => 'Attribute deleted successfully',
            ],
        ]);

        $this->assertSoftDeleted('attributes', ['id' => $attribute->id]);
    }

    public function test_bulk_delete_attributes(): void
    {
        $attributes = Attribute::factory()->count(3)->create(['company_id' => $this->companyId]);
        $ids = $attributes->pluck('id')->toArray();

        $response = $this->postJson('/api/attributes/bulk-delete', [
            'ids' => $ids,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'message' => 'Attributes deleted successfully',
                'deleted' => 3,
            ],
        ]);
    }

    public function test_create_attribute_requires_authentication(): void
    {
        $response = $this->postJson('/api/attributes', [
            'name' => 'Size',
            'displayName' => 'Product Size',
            'optionType' => 'dropdown',
        ]);

        $response->assertStatus(401);
    }
}
