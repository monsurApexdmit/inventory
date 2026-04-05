<?php

namespace App\Http\Controllers\Api\Team;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\InviteTeamMemberRequest;
use App\Http\Requests\Team\UpdateTeamMemberRoleRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Team\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TeamService $teamService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->teamService->list($companyId));
    }

    public function invite(InviteTeamMemberRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $userId = (int) $request->attributes->get('auth_user_id');
        $dto = $this->teamService->invite($companyId, $userId, $request->validated());

        return $this->success(
            $dto->toArray(),
            'Invitation sent successfully',
            201
        );
    }

    public function resendInvitation(Request $request, int $invitationId): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->teamService->resendInvitation($invitationId, $companyId);

        return $this->success(['message' => 'Invitation resent']);
    }

    public function updateRole(UpdateTeamMemberRoleRequest $request, int $userId): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $actorId = (int) $request->attributes->get('auth_user_id');
        $validated = $request->validated();
        $dto = $this->teamService->updateRole($userId, $companyId, $actorId, $validated['role']);

        return $this->success($dto->toArray());
    }

    public function remove(Request $request, int $userId): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $actorId = (int) $request->attributes->get('auth_user_id');

        $this->teamService->remove($userId, $companyId, $actorId);

        return $this->success(['message' => 'Team member removed']);
    }
}
