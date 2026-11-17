<?php

namespace Tests\Feature\Setting;

use App\Models\Company;
use App\Models\Role;
use App\Models\SaasUser;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Storefront theme picked in admin (Online store > Theme) and served to the storefront. */
class StoreThemeTest extends TestCase
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
        $owner = SaasUser::factory()->owner()->active()->forCompany($company)->create();
        $this->token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($owner);
    }

    private function saveOnlineStore(array $onlineStore)
    {
        return $this->patchJson('/api/settings/business', ['onlineStore' => array_merge(['name' => 'Aura'], $onlineStore)], [
            'Authorization' => "Bearer {$this->token}",
        ]);
    }

    private function publicOnlineStore(): array
    {
        return $this->getJson("/api/store/settings/online-store?company_id={$this->companyId}")->assertOk()->json('data');
    }

    public function test_saved_theme_and_accent_reach_the_storefront(): void
    {
        $this->saveOnlineStore(['themePreset' => 'luxe', 'theme' => '#0F766E', 'colorSource' => 'theme'])->assertOk();

        $data = $this->publicOnlineStore();
        $this->assertSame('luxe', $data['themePreset']);
        $this->assertSame('#0F766E', $data['theme']);
        $this->assertSame('theme', $data['colorSource']);
    }

    public function test_accent_can_be_left_to_the_theme(): void
    {
        $this->saveOnlineStore(['themePreset' => 'fresh', 'theme' => null])->assertOk();

        $data = $this->publicOnlineStore();
        $this->assertSame('fresh', $data['themePreset']);
        $this->assertSame('', $data['theme']);
    }

    public function test_unknown_theme_or_colour_source_is_rejected(): void
    {
        $this->saveOnlineStore(['themePreset' => 'neon'])->assertStatus(422)->assertJsonValidationErrors('onlineStore.themePreset');
        $this->saveOnlineStore(['colorSource' => 'rainbow'])->assertStatus(422)->assertJsonValidationErrors('onlineStore.colorSource');
    }

    public function test_stores_without_a_theme_get_the_default(): void
    {
        $data = $this->publicOnlineStore();
        $this->assertSame('editorial', $data['themePreset']);
        // Brand colours keep applying until the owner picks theme colours
        $this->assertSame('brand', $data['colorSource']);

        // A value saved before the list changed is not passed through
        Setting::where('company_id', $this->companyId)->update(['business_settings' => ['onlineStore' => ['themePreset' => 'retired']]]);
        $this->assertSame('editorial', $this->publicOnlineStore()['themePreset']);
    }
}
