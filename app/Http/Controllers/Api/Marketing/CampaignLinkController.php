<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\CampaignLinkStatusRequest;
use App\Http\Requests\Marketing\StoreCampaignLinkRequest;
use App\Http\Requests\Marketing\UpdateCampaignLinkRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Marketing\CampaignLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Marketing → Ad order links (contract: AD_ORDERS_API_CONTRACT.md §3). */
class CampaignLinkController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CampaignLinkService $service)
    {
    }

    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('auth_company_id');
    }

    public function index(Request $request): JsonResponse
    {
        $result = $this->service->list($this->companyId($request), $request->only(['search', 'channel', 'targetType', 'isActive', 'period', 'from', 'to', 'page', 'perPage', 'per_page', 'limit']));

        return $this->success($result['data'], 'Campaign links retrieved', 200, ['pagination' => $result['pagination'], 'period' => $result['period']]);
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->success($this->service->summary($this->companyId($request), $request->only(['period', 'from', 'to'])));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->get($id, $this->companyId($request))->toArray());
    }

    public function store(StoreCampaignLinkRequest $request): JsonResponse
    {
        $userId = $request->attributes->get('auth_user_id');
        $isLegacy = (bool) $request->attributes->get('auth_is_legacy', false);
        $dto = $this->service->create($this->companyId($request), $userId && !$isLegacy ? (int) $userId : null, $request->validated());

        return $this->success($dto->toArray(), 'Campaign link created', 201);
    }

    public function update(UpdateCampaignLinkRequest $request, int $id): JsonResponse
    {
        return $this->success($this->service->update($id, $this->companyId($request), $request->validated())->toArray(), 'Campaign link updated');
    }

    public function status(CampaignLinkStatusRequest $request, int $id): JsonResponse
    {
        $dto = $this->service->setStatus($id, $this->companyId($request), (bool) $request->validated()['isActive']);

        return $this->success($dto->toArray(), $dto->isActive ? 'Link activated' : 'Link paused');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->delete($id, $this->companyId($request));

        return $this->success(null, 'Campaign link deleted');
    }

    public function stats(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->linkStats($id, $this->companyId($request), $request->only(['period', 'from', 'to'])));
    }
}
