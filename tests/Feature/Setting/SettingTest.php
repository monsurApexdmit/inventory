<?php

namespace Tests\Feature\Setting;

use App\Models\Company;
use App\Models\Role;
use App\Models\SaasUser;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingTest extends TestCase
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

    public function test_get_settings_auto_creates_defaults(): void
    {
        $response = $this->getJson('/api/settings', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'general',
                'tax',
                'shipping',
                'payment',
                'business',
                'regional',
                'notifications',
                'store-hours',
            ],
        ]);

        $this->assertDatabaseHas('settings', [
            'company_id' => $this->companyId,
        ]);
    }

    public function test_get_all_settings(): void
    {
        Setting::factory()->create(['company_id' => $this->companyId]);

        $response = $this->getJson('/api/settings', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'general' => [],
                'tax' => [],
                'shipping' => [],
                'payment' => [],
                'business' => [],
                'regional' => [],
                'notifications' => [],
                'store-hours' => [],
            ],
        ]);
    }

    public function test_update_general_settings(): void
    {
        $response = $this->putJson('/api/settings/general', [
            'storeName' => 'My Store',
            'storeEmail' => 'store@example.com',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'general' => [
                    'storeName' => 'My Store',
                    'storeEmail' => 'store@example.com',
                ],
            ],
        ]);

        $this->assertDatabaseHas('settings', [
            'company_id' => $this->companyId,
        ]);
    }

    public function test_update_tax_settings(): void
    {
        $response = $this->putJson('/api/settings/tax', [
            'taxRate' => 10.5,
            'taxId' => 'TAX123',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'tax' => [
                    'taxRate' => 10.5,
                    'taxId' => 'TAX123',
                ],
            ],
        ]);
    }

    public function test_update_regional_settings(): void
    {
        $response = $this->putJson('/api/settings/regional', [
            'currency' => 'USD',
            'timezone' => 'America/New_York',
            'language' => 'en',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'regional' => [
                    'currency' => 'USD',
                    'timezone' => 'America/New_York',
                    'language' => 'en',
                ],
            ],
        ]);
    }

    public function test_section_update_does_not_affect_other_sections(): void
    {
        // Set general settings
        $this->putJson('/api/settings/general', [
            'storeName' => 'My Store',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        // Update tax settings
        $this->putJson('/api/settings/tax', [
            'taxRate' => 10.5,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        // Verify general settings unchanged
        $response = $this->getJson('/api/settings', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertJson([
            'success' => true,
            'data' => [
                'general' => ['storeName' => 'My Store'],
                'tax' => ['taxRate' => 10.5],
            ],
        ]);
    }

    public function test_update_shipping_settings(): void
    {
        $response = $this->putJson('/api/settings/shipping', [
            'shippingCost' => 5.99,
            'freeShippingThreshold' => 50,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'shipping' => [
                    'shippingCost' => 5.99,
                    'freeShippingThreshold' => 50,
                ],
            ],
        ]);
    }

    public function test_update_payment_settings(): void
    {
        $response = $this->putJson('/api/settings/payment', [
            'paypalEnabled' => true,
            'stripeEnabled' => true,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'payment' => [
                    'paypalEnabled' => true,
                    'stripeEnabled' => true,
                ],
            ],
        ]);
    }

    public function test_settings_requires_authentication(): void
    {
        $response = $this->getJson('/api/settings');

        $response->assertStatus(401);
    }

    public function test_update_invalid_section_returns_400(): void
    {
        $response = $this->putJson('/api/settings/invalid-section', [
            'data' => 'value',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(400);
    }
}
