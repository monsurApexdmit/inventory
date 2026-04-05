<?php

namespace App\Services\Team;

use App\Repositories\Contracts\IInvitationRepository;
use App\Repositories\Contracts\ISubscriptionRepository;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TeamService
{
    public function __construct(
        private readonly IInvitationRepository $invitationRepository,
        private readonly ISubscriptionRepository $subscriptionRepository,
    ) {
    }

    public function list(int $companyId): array
    {
        // Get subscription to check max users
        $subscription = $this->subscriptionRepository->findByCompanyId($companyId);

        return [
            'companyId' => $companyId,
            'maxUsers' => $subscription?->plan?->max_users ?? 10,
            'activeUsers' => 0, // Will be populated by controller if needed
        ];
    }

    public function invite(int $companyId, int $inviterId, array $data): array
    {
        $token = Str::random(64);
        $expiresAt = now()->addDays(7);

        $invitation = $this->invitationRepository->create([
            'company_id' => $companyId,
            'email' => $data['email'],
            'full_name' => $data['fullName'] ?? null,
            'role_id' => $data['roleId'] ?? null,
            'status' => 'pending',
            'invitation_token' => $token,
            'expires_at' => $expiresAt,
            'invited_at' => now(),
        ]);

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'fullName' => $invitation->full_name,
            'roleId' => $invitation->role_id,
            'status' => $invitation->status,
            'expiresAt' => $invitation->expires_at,
            'invitationToken' => $token,
        ];
    }

    public function resendInvitation(int $invitationId, int $companyId): void
    {
        $invitation = $this->invitationRepository->findById($invitationId);

        if (!$invitation || $invitation->company_id !== $companyId) {
            throw new HttpException(404, 'Invitation not found');
        }

        if ($invitation->status !== 'pending') {
            throw new HttpException(400, 'Only pending invitations can be resent');
        }

        // Update expiration date
        $this->invitationRepository->update($invitationId, [
            'expires_at' => now()->addDays(7),
            'invited_at' => now(),
        ]);
    }

    public function acceptInvitation(array $data): array
    {
        $token = $data['invitationToken'];
        $invitation = $this->invitationRepository->findByToken($token);

        if (!$invitation) {
            throw new HttpException(404, 'Invalid invitation token');
        }

        if ($invitation->status !== 'pending') {
            throw new HttpException(400, 'This invitation has already been accepted');
        }

        if ($invitation->expires_at->isPast()) {
            throw new HttpException(400, 'This invitation has expired');
        }

        $this->invitationRepository->update($invitation->id, [
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        return [
            'id' => $invitation->id,
            'companyId' => $invitation->company_id,
            'email' => $invitation->email,
            'status' => 'accepted',
            'acceptedAt' => now(),
        ];
    }

    public function updateRole(int $userId, int $companyId, int $actorId, string $role): array
    {
        // This would be implemented with actual user/role relationship
        // Placeholder for now
        return [
            'userId' => $userId,
            'companyId' => $companyId,
            'role' => $role,
        ];
    }

    public function remove(int $userId, int $companyId, int $actorId): void
    {
        // This would be implemented with actual user/company removal
        // Placeholder for now
    }
}
