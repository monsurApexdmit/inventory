<?php

namespace Tests\Feature\Team;

use App\Models\Company;
use App\Models\Invitation;
use App\Models\SaasUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        $this->companyId = $company->id;

        $owner = SaasUser::factory()
            ->owner()
            ->active()
            ->forCompany($company)
            ->create();

        $this->token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($owner);
    }

    public function test_list_team(): void
    {
        $response = $this->getJson('/api/auth/team/', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => ['companyId', 'maxUsers', 'activeUsers'],
        ]);
    }

    public function test_invite_team_member(): void
    {
        $response = $this->postJson('/api/auth/team/invite', [
            'email' => 'newmember@example.com',
            'fullName' => 'New Member',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => ['id', 'email', 'status', 'invitationToken'],
        ]);

        $this->assertDatabaseHas('invitations', [
            'company_id' => $this->companyId,
            'email' => 'newmember@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_resend_invitation(): void
    {
        $invitation = Invitation::factory()->create([
            'company_id' => $this->companyId,
            'status' => 'pending',
        ]);

        $response = $this->postJson(
            "/api/auth/team/{$invitation->id}/resend-invitation",
            [],
            ['Authorization' => "Bearer {$this->token}"]
        );

        $response->assertStatus(200);
    }

    public function test_accept_invitation(): void
    {
        $invitation = Invitation::factory()->create([
            'company_id' => $this->companyId,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->postJson('/api/auth/accept-invitation', [
            'invitationToken' => $invitation->invitation_token,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => 'accepted',
        ]);
    }

    public function test_accept_invalid_invitation(): void
    {
        $response = $this->postJson('/api/auth/accept-invitation', [
            'invitationToken' => 'invalid-token',
        ]);

        $response->assertStatus(404);
    }

    public function test_accept_expired_invitation(): void
    {
        $invitation = Invitation::factory()->create([
            'company_id' => $this->companyId,
            'status' => 'pending',
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->postJson('/api/auth/accept-invitation', [
            'invitationToken' => $invitation->invitation_token,
        ]);

        $response->assertStatus(400);
    }

    public function test_accept_already_accepted_invitation(): void
    {
        $invitation = Invitation::factory()->create([
            'company_id' => $this->companyId,
            'status' => 'accepted',
        ]);

        $response = $this->postJson('/api/auth/accept-invitation', [
            'invitationToken' => $invitation->invitation_token,
        ]);

        $response->assertStatus(400);
    }

    public function test_invite_requires_email(): void
    {
        $response = $this->postJson('/api/auth/team/invite', [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_team_requires_authentication(): void
    {
        $response = $this->getJson('/api/auth/team/');

        $response->assertStatus(401);
    }

    public function test_update_team_member_role(): void
    {
        $response = $this->putJson('/api/auth/team/1/role', [
            'role' => 'admin',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_remove_team_member(): void
    {
        $response = $this->deleteJson('/api/auth/team/1', [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
    }
}
