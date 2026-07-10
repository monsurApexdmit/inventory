<?php

namespace Tests\Feature\Product;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaasUser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
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

    public function test_list_products(): void
    {
        Product::factory()->create(['company_id' => $this->companyId]);

        $response = $this->getJson('/api/products', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'data' => [
                    '*' => ['id', 'name', 'price', 'stock', 'published'],
                ],
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
        ]);
    }

    public function test_list_products_with_search_filter(): void
    {
        Product::factory()->create(['company_id' => $this->companyId, 'name' => 'Test Product']);
        Product::factory()->create(['company_id' => $this->companyId, 'name' => 'Other Product']);

        $response = $this->getJson('/api/products?search=Test', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
    }

    public function test_list_products_with_published_filter(): void
    {
        Product::factory()->published()->create(['company_id' => $this->companyId]);
        Product::factory()->unpublished()->create(['company_id' => $this->companyId]);

        $response = $this->getJson('/api/products?published=1', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
    }

    public function test_get_product(): void
    {
        $category = Category::factory()->create(['company_id' => $this->companyId]);
        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $product = Product::factory()->create([
            'company_id' => $this->companyId,
            'category_id' => $category->id,
            'location_id' => $location->id,
        ]);

        $response = $this->getJson("/api/products/{$product->id}", [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
            ],
        ]);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id', 'name', 'price', 'stock', 'category', 'variants', 'images', 'attributes',
            ],
        ]);
    }

    public function test_create_product(): void
    {
        $response = $this->postJson('/api/products', [
            'name' => 'New Product',
            'price' => 99.99,
            'stock' => 100,
            'published' => true,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('products', [
            'company_id' => $this->companyId,
            'name' => 'New Product',
        ]);
    }

    public function test_create_product_with_variants(): void
    {
        $category = Category::factory()->create(['company_id' => $this->companyId]);
        $location = Location::factory()->create(['company_id' => $this->companyId]);

        $response = $this->postJson('/api/products', [
            'name' => 'Product with Variants',
            'categoryId' => $category->id,
            'locationId' => $location->id,
            'price' => 50.00,
            'variants' => [
                ['name' => 'Variant 1', 'price' => 50.00, 'sku' => 'VAR1'],
                ['name' => 'Variant 2', 'price' => 60.00, 'sku' => 'VAR2'],
            ],
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('products', [
            'company_id' => $this->companyId,
            'name' => 'Product with Variants',
        ]);
        $this->assertDatabaseHas('product_variants', [
            'name' => 'Variant 1',
        ]);
    }

    public function test_update_product(): void
    {
        $product = Product::factory()->create(['company_id' => $this->companyId]);

        $response = $this->putJson("/api/products/{$product->id}", [
            'name' => 'Updated Product',
            'price' => 199.99,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated Product',
        ]);
    }

    public function test_update_product_status(): void
    {
        $product = Product::factory()->unpublished()->create(['company_id' => $this->companyId]);

        $response = $this->patchJson("/api/products/{$product->id}/status", [
            'published' => true,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'published' => true,
        ]);
    }

    public function test_delete_product(): void
    {
        $product = Product::factory()->create(['company_id' => $this->companyId]);

        $response = $this->deleteJson("/api/products/{$product->id}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_product_not_found(): void
    {
        $response = $this->getJson('/api/products/999', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(404);
    }

    public function test_create_product_requires_name(): void
    {
        $response = $this->postJson('/api/products', [
            'price' => 99.99,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_product_requires_authentication(): void
    {
        $response = $this->getJson('/api/products');

        $response->assertStatus(401);
    }
}
