<?php

namespace App\Services\Team;

use App\Repositories\Contracts\IInvitationRepository;
use App\Repositories\Contracts\ISubscriptionRepository;
use App\Services\Company\PlanLimitService;
use App\Models\Invitation;
use App\Models\SaasUser;
use App\Models\StaffRole;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TeamService
{
    public function __construct(
        private readonly IInvitationRepository $invitationRepository,
        private readonly ISubscriptionRepository $subscriptionRepository,
        private readonly PlanLimitService $planLimitService,
    ) {
    }

    public function list(int $companyId): array
    {
        // Get subscription to check max users
        $subscription = $this->subscriptionRepository->findByCompanyId($companyId);
        $maxUsers = $subscription?->plan?->max_users ?? 10;

        $saasUsers = SaasUser::with('staffRole:id,name,description')
            ->where('company_id', $companyId)
            ->whereIn('status', ['active', 'inactive'])
            ->get();

        $users = $saasUsers->map(fn (SaasUser $user) => [
            'id' => $user->id,
            'rowType' => 'user',
            'invitationId' => null,
            'companyId' => $user->company_id,
            'email' => $user->email,
            'fullName' => $user->full_name,
            'phone' => $user->phone,
            'role' => $user->role,
            'roleId' => $user->role_id,
            'staffRole' => $user->staffRole ? [
                'id' => $user->staffRole->id,
                'name' => $user->staffRole->name,
            ] : null,
            'status' => $user->status,
            'joinedDate' => $user->joined_date?->toIso8601String() ?? $user->created_at?->toIso8601String(),
            'lastLogin' => $user->last_login?->toIso8601String(),
            'avatar' => $user->avatar,
            'isOwner' => $user->role === 'owner',
        ]);

        $invitations = Invitation::with('role:id,name,description')
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->get();

        $invited = $invitations->map(fn (Invitation $inv) => [
            'id' => $inv->id,
            'rowType' => 'invitation',
            'invitationId' => $inv->id,
            'companyId' => $inv->company_id,
            'email' => $inv->email,
            'fullName' => $inv->full_name ?? '',
            'phone' => $inv->phone,
            'role' => 'staff',
            'roleId' => $inv->role_id,
            'staffRole' => $inv->role ? ['id' => $inv->role->id, 'name' => $inv->role->name] : null,
            'status' => 'invited',
            'joinedDate' => null,
            'invitedAt' => ($inv->invited_at ?? $inv->created_at)?->toIso8601String(),
            'lastLogin' => null,
            'avatar' => null,
            'isOwner' => false,
            'invitationExpired' => $inv->expires_at?->isPast() ?? false,
        ]);

        $rows = $users->concat($invited)->values()->all();
        $activeCount = $saasUsers->where('status', 'active')->count();
        $seatsUsed = $activeCount + $invitations->count();

        return [
            'users' => $rows,
            'companyId' => $companyId,
            'maxUsers' => $maxUsers,
            'planName' => $subscription?->plan?->name,
            'activeUsers' => $activeCount,
            'invitedUsers' => $invitations->count(),
            'totalUsers' => count($rows),
            'seatsUsed' => $seatsUsed,
            'canAddMore' => $seatsUsed < $maxUsers,
        ];
    }

    public function invite(int $companyId, int $inviterId, array $data): array
    {
        $email = strtolower(trim($data['email']));

        if (SaasUser::where('company_id', $companyId)->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw new HttpException(422, 'A team member with this email already exists.');
        }
        if (Invitation::where('company_id', $companyId)->where('status', 'pending')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw new HttpException(422, 'A pending invitation for this email already exists.');
        }

        $this->planLimitService->enforceUserLimit($companyId);
        $limits = $this->planLimitService->getLimits($companyId);
        $pending = Invitation::where('company_id', $companyId)->where('status', 'pending')->count();
        if ($limits['currentUsers'] + $pending >= $limits['maxUsers']) {
            throw new HttpException(422, "User limit reached ({$limits['currentUsers']} active + {$pending} invited of {$limits['maxUsers']}). Upgrade your plan to add more team members.");
        }

        $token = Str::random(64);
        $expiresAt = now()->addDays(7);

        $invitation = $this->invitationRepository->create([
            'company_id' => $companyId,
            'email' => $email,
            'full_name' => $data['fullName'] ?? null,
            'phone' => $data['phone'] ?? null,
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
            'phone' => $invitation->phone,
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

        // Refresh the expiry window
        $this->invitationRepository->update($invitationId, [
            'expires_at' => now()->addDays(7),
            'invited_at' => now(),
        ]);
    }

    /** Edit a pending invitation (name, phone, email, role). No email is sent. */
    public function updateInvitation(int $invitationId, int $companyId, array $data): array
    {
        $invitation = $this->invitationRepository->findById($invitationId);

        if (!$invitation || $invitation->company_id !== $companyId) {
            throw new HttpException(404, 'Invitation not found');
        }
        if ($invitation->status !== 'pending') {
            throw new HttpException(400, 'Only pending invitations can be edited');
        }

        $patch = [];
        if (!empty($data['email'])) {
            $email = strtolower(trim($data['email']));
            if ($email !== strtolower($invitation->email)) {
                if (SaasUser::where('company_id', $companyId)->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    throw new HttpException(422, 'A team member with this email already exists.');
                }
                if (Invitation::where('company_id', $companyId)->where('status', 'pending')->where('id', '!=', $invitationId)->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    throw new HttpException(422, 'A pending invitation for this email already exists.');
                }
                $patch['email'] = $email;
            }
        }
        if (array_key_exists('fullName', $data) && $data['fullName'] !== null) {
            $patch['full_name'] = $data['fullName'];
        }
        if (array_key_exists('phone', $data)) {
            $patch['phone'] = $data['phone'] ?: null;
        }
        if (!empty($data['roleId'])) {
            $this->assertRoleBelongsToCompany((int) $data['roleId'], $companyId);
            $patch['role_id'] = (int) $data['roleId'];
        }

        if ($patch) {
            $this->invitationRepository->update($invitationId, $patch);
        }

        $fresh = Invitation::with('role:id,name')->find($invitationId);

        return [
            'id' => $fresh->id,
            'rowType' => 'invitation',
            'email' => $fresh->email,
            'fullName' => $fresh->full_name,
            'phone' => $fresh->phone,
            'roleId' => $fresh->role_id,
            'staffRole' => $fresh->role ? ['id' => $fresh->role->id, 'name' => $fresh->role->name] : null,
            'status' => 'invited',
        ];
    }

    public function revokeInvitation(int $invitationId, int $companyId): void
    {
        $invitation = $this->invitationRepository->findById($invitationId);

        if (!$invitation || $invitation->company_id !== $companyId) {
            throw new HttpException(404, 'Invitation not found');
        }

        if ($invitation->status !== 'pending') {
            throw new HttpException(400, 'Only pending invitations can be revoked');
        }

        $this->invitationRepository->update($invitationId, ['status' => 'revoked']);
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

        // When a password is supplied the invitee becomes an active team member.
        if (!empty($data['password'])) {
            $user = SaasUser::withTrashed()
                ->where('company_id', $invitation->company_id)
                ->whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])
                ->first();

            $attrs = [
                'full_name' => $data['fullName'] ?? $invitation->full_name ?? $invitation->email,
                'phone' => $invitation->phone,
                'password' => $data['password'],
                'role' => 'staff',
                'role_id' => $invitation->role_id,
                'status' => 'active',
                'joined_date' => now(),
            ];

            if ($user) {
                if ($user->trashed()) {
                    $user->restore();
                }
                $user->update($attrs);
            } else {
                SaasUser::create($attrs + [
                    'company_id' => $invitation->company_id,
                    'email' => $invitation->email,
                ]);
            }
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

    public function updateRole(int $userId, int $companyId, int $actorId, ?string $role = null, ?int $roleId = null): array
    {
        $user = $this->findMember($userId, $companyId);
        $this->assertMutable($user, $actorId);

        $patch = [];
        if ($roleId) {
            $this->assertRoleBelongsToCompany($roleId, $companyId);
            $patch['role_id'] = $roleId;
            $patch['role'] = 'staff';
        } elseif ($role !== null && $role !== 'owner') {
            $patch['role'] = $role;
        }

        if ($patch) {
            $user->update($patch);
        }

        return $this->formatMember($user->fresh('staffRole'));
    }

    public function update(int $userId, int $companyId, int $actorId, array $data): array
    {
        $user = $this->findMember($userId, $companyId);
        $isOwner = $user->role === 'owner';
        $isSelf = $user->id === $actorId;

        $patch = [];
        if (array_key_exists('fullName', $data) && $data['fullName'] !== null) {
            $patch['full_name'] = $data['fullName'];
        }
        if (array_key_exists('phone', $data)) {
            $patch['phone'] = $data['phone'] ?: null;
        }
        if (!empty($data['roleId'])) {
            if ($isOwner || $isSelf) {
                throw new HttpException(403, 'This member\'s role cannot be changed.');
            }
            $this->assertRoleBelongsToCompany((int) $data['roleId'], $companyId);
            $patch['role_id'] = (int) $data['roleId'];
            $patch['role'] = 'staff';
        }

        if ($patch) {
            $user->update($patch);
        }

        return $this->formatMember($user->fresh('staffRole'));
    }

    public function setStatus(int $userId, int $companyId, int $actorId, string $status): array
    {
        $user = $this->findMember($userId, $companyId);
        $this->assertMutable($user, $actorId);

        $user->update(['status' => $status]);

        return $this->formatMember($user->fresh('staffRole'));
    }

    public function remove(int $userId, int $companyId, int $actorId): void
    {
        $user = $this->findMember($userId, $companyId);
        $this->assertMutable($user, $actorId);

        $user->delete();
    }

    private function findMember(int $userId, int $companyId): SaasUser
    {
        $user = SaasUser::where('company_id', $companyId)->where('id', $userId)->first();
        if (!$user) {
            throw new HttpException(404, 'Team member not found');
        }

        return $user;
    }

    private function assertMutable(SaasUser $user, int $actorId): void
    {
        if ($user->role === 'owner') {
            throw new HttpException(403, 'The owner cannot be changed or removed.');
        }
        if ($user->id === $actorId) {
            throw new HttpException(403, 'You cannot perform this action on your own account.');
        }
    }

    private function assertRoleBelongsToCompany(int $roleId, int $companyId): void
    {
        $role = StaffRole::where('id', $roleId)->where('company_id', $companyId)->first();
        if (!$role) {
            throw new HttpException(422, 'Selected role does not exist.');
        }
        if ($role->isLocked()) {
            throw new HttpException(422, 'This role cannot be assigned to team members.');
        }
    }

    private function formatMember(SaasUser $user): array
    {
        return [
            'id' => $user->id,
            'rowType' => 'user',
            'email' => $user->email,
            'fullName' => $user->full_name,
            'phone' => $user->phone,
            'role' => $user->role,
            'roleId' => $user->role_id,
            'staffRole' => $user->staffRole ? ['id' => $user->staffRole->id, 'name' => $user->staffRole->name] : null,
            'status' => $user->status,
            'joinedDate' => $user->joined_date?->toIso8601String(),
            'lastLogin' => $user->last_login?->toIso8601String(),
        ];
    }
}
