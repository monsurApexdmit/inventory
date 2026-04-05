<?php

namespace Tests\Feature\User;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyUserTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::factory()->create();

        $user = User::factory()->create(['role_id' => $role->id]);
        $this->token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);
    }

    public function test_list_users(): void
    {
        User::factory()->count(3)->create();

        $response = $this->getJson('/api/users', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'username', 'email', 'roleId'],
            ],
        ]);
    }

    public function test_get_user(): void
    {
        $user = User::factory()->create();

        $response = $this->getJson("/api/users/{$user->id}", [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
            ],
        ]);
    }

    public function test_create_user(): void
    {
        $role = Role::factory()->create();

        $response = $this->postJson('/api/users', [
            'username' => 'newuser',
            'email' => 'newuser@example.com',
            'password' => 'Password123',
            'roleId' => $role->id,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'data' => [
                'username' => 'newuser',
                'email' => 'newuser@example.com',
            ],
        ]);

        $this->assertDatabaseHas('users', [
            'username' => 'newuser',
            'email' => 'newuser@example.com',
        ]);
    }

    public function test_update_user(): void
    {
        $user = User::factory()->create();

        $response = $this->putJson("/api/users/{$user->id}", [
            'username' => 'updateduser',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'username' => 'updateduser',
        ]);
    }

    public function test_delete_user(): void
    {
        $user = User::factory()->create();

        $response = $this->deleteJson("/api/users/{$user->id}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(204);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_create_user_with_duplicate_email(): void
    {
        $existing = User::factory()->create();
        $role = Role::factory()->create();

        $response = $this->postJson('/api/users', [
            'username' => 'newuser',
            'email' => $existing->email,
            'password' => 'Password123',
            'roleId' => $role->id,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_users_require_authentication(): void
    {
        $response = $this->getJson('/api/users');

        $response->assertStatus(401);
    }

    public function test_user_not_found(): void
    {
        $response = $this->getJson('/api/users/999', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(404);
    }

    public function test_create_user_requires_username(): void
    {
        $role = Role::factory()->create();

        $response = $this->postJson('/api/users', [
            'email' => 'test@example.com',
            'password' => 'Password123',
            'roleId' => $role->id,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('username');
    }
}
