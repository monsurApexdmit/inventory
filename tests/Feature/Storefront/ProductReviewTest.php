<?php

namespace Tests\Feature\Storefront;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\SaasUser;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Product $product;
    private Customer $customer;
    private string $customerToken;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->product = Product::factory()->create([
            'company_id' => $this->company->id,
            'published' => true,
        ]);
        $this->customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Review Customer',
            'email' => 'review@example.com',
            'status' => 'active',
        ]);

        $payload = base64_encode("{$this->customer->id}|{$this->company->id}|".time());
        $sig = hash_hmac('sha256', $payload, config('app.key'));
        $this->customerToken = "{$payload}.{$sig}";

        $admin = SaasUser::factory()->forCompany($this->company)->create();
        $this->adminToken = JWTAuth::fromUser($admin);
    }

    public function test_public_reviews_endpoint_returns_summary(): void
    {
        ProductReview::query()->create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'customer_email' => $this->customer->email,
            'rating' => 5,
            'comment' => 'Excellent product.',
        ]);

        $response = $this->getJson("/api/store/products/{$this->product->id}/reviews?company_id={$this->company->id}");

        $response->assertOk()
            ->assertJsonPath('data.summary.average_rating', 5)
            ->assertJsonPath('data.summary.review_count', 1)
            ->assertJsonCount(1, 'data.reviews');
    }

    public function test_customer_can_create_or_update_review(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson("/api/store/products/{$this->product->id}/reviews?company_id={$this->company->id}", [
                'rating' => 4,
                'comment' => 'Nice quality and delivery.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.review.rating', 4);

        $this->assertDatabaseHas('product_reviews', [
            'product_id' => $this->product->id,
            'customer_id' => $this->customer->id,
            'rating' => 4,
        ]);
        $this->assertDatabaseHas('notifications', [
            'company_id' => $this->company->id,
            'type' => NotificationService::TYPE_PRODUCT_REVIEW,
            'title' => 'New Product Review',
        ]);

        $second = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson("/api/store/products/{$this->product->id}/reviews?company_id={$this->company->id}", [
                'rating' => 5,
                'comment' => 'Updated review after second use.',
            ]);

        $second->assertCreated()
            ->assertJsonPath('data.review.rating', 5);

        $this->assertDatabaseCount('product_reviews', 1);
        $this->assertSame(1, Notification::query()
            ->where('company_id', $this->company->id)
            ->where('type', NotificationService::TYPE_PRODUCT_REVIEW)
            ->count());
    }

    public function test_admin_can_reply_to_review(): void
    {
        $review = ProductReview::query()->create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'customer_email' => $this->customer->email,
            'rating' => 5,
            'comment' => 'Amazing support.',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/products/{$this->product->id}/reviews/{$review->id}/reply", [
                'reply' => 'Thanks for your feedback.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.reply.body', 'Thanks for your feedback.');

        $this->assertDatabaseHas('product_reviews', [
            'id' => $review->id,
            'reply_body' => 'Thanks for your feedback.',
        ]);
    }
}
