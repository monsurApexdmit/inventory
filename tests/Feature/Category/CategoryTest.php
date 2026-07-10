<?php

namespace Tests\Feature\Category;

use App\Models\Category;
use App\Models\Company;
use App\Models\Role;
use App\Models\SaasUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
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

    public function test_list_categories(): void
    {
        Category::factory()->count(5)->create(['company_id' => $this->companyId]);

        $response = $this->getJson('/api/categories', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'data' => [
                    '*' => ['id', 'categoryName', 'status'],
                ],
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
        ]);
    }

    public function test_get_simple_categories(): void
    {
        Category::factory()->count(3)->create(['company_id' => $this->companyId, 'status' => true]);

        $response = $this->getJson('/api/categories/simple', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'categoryName'],
            ],
        ]);
    }

    public function test_get_category_stats(): void
    {
        Category::factory()->count(3)->create(['company_id' => $this->companyId, 'status' => true]);
        Category::factory()->count(2)->create(['company_id' => $this->companyId, 'status' => false]);

        $response = $this->getJson('/api/categories/stats', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'total' => 5,
                'active' => 3,
                'inactive' => 2,
            ],
        ]);
    }

    public function test_get_category(): void
    {
        $category = Category::factory()->create(['company_id' => $this->companyId]);

        $response = $this->getJson("/api/categories/{$category->id}", [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $category->id,
                'categoryName' => $category->category_name,
            ],
        ]);
    }

    public function test_create_category(): void
    {
        $response = $this->postJson('/api/categories', [
            'categoryName' => 'Electronics',
            'status' => true,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'data' => [
                'categoryName' => 'Electronics',
                'status' => true,
            ],
        ]);

        $this->assertDatabaseHas('categories', [
            'company_id' => $this->companyId,
            'category_name' => 'Electronics',
        ]);
    }

    public function test_create_duplicate_category_returns_409(): void
    {
        Category::factory()->create([
            'company_id' => $this->companyId,
            'category_name' => 'Electronics',
        ]);

        $response = $this->postJson('/api/categories', [
            'categoryName' => 'Electronics',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(409);
    }

    public function test_update_category(): void
    {
        $category = Category::factory()->create(['company_id' => $this->companyId]);

        $response = $this->putJson("/api/categories/{$category->id}", [
            'categoryName' => 'Updated Electronics',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'categoryName' => 'Updated Electronics',
            ],
        ]);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'category_name' => 'Updated Electronics',
        ]);
    }

    public function test_toggle_category_status(): void
    {
        $category = Category::factory()->create(['company_id' => $this->companyId, 'status' => true]);

        $response = $this->patchJson("/api/categories/{$category->id}/toggle-status", [], [
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

    public function test_delete_category(): void
    {
        $category = Category::factory()->create(['company_id' => $this->companyId]);

        $response = $this->deleteJson("/api/categories/{$category->id}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'message' => 'Category deleted successfully',
            ],
        ]);

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    public function test_delete_category_with_children_returns_400(): void
    {
        $parent = Category::factory()->create(['company_id' => $this->companyId]);
        Category::factory()->create([
            'company_id' => $this->companyId,
            'parent_id' => $parent->id,
        ]);

        $response = $this->deleteJson("/api/categories/{$parent->id}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(400);
    }

    public function test_create_category_requires_authentication(): void
    {
        $response = $this->postJson('/api/categories', [
            'categoryName' => 'Electronics',
        ]);

        $response->assertStatus(401);
    }

    public function test_bulk_delete_categories(): void
    {
        $categories = Category::factory()->count(3)->create(['company_id' => $this->companyId]);
        $ids = $categories->pluck('id')->toArray();

        $response = $this->postJson('/api/categories/bulk-delete', [
            'ids' => $ids,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'message' => 'Categories deleted successfully',
                'deleted' => 3,
            ],
        ]);
    }
}
