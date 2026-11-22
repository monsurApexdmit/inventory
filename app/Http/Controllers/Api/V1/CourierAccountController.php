<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipping\StoreCourierAccountRequest;
use App\Http\Requests\Shipping\UpdateCourierAccountRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Courier\CourierAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Shipping methods → Courier integrations (contract: COURIER_API_CONTRACT.md §2). */
class CourierAccountController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CourierAccountService $service)
    {
    }

    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('auth_company_id');
    }

    public function index(Request $request): JsonResponse
    {
        return $this->success($this->service->list($this->companyId($request)));
    }

    public function store(StoreCourierAccountRequest $request): JsonResponse
    {
        return $this->success($this->service->create($this->companyId($request), $request->validated()), 'Courier account added', 201);
    }

    public function update(UpdateCourierAccountRequest $request, int $id): JsonResponse
    {
        return $this->success($this->service->update($id, $this->companyId($request), $request->validated()), 'Courier account updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->delete($id, $this->companyId($request));

        return $this->success(null, 'Courier account removed');
    }

    public function test(Request $request, int $id): JsonResponse
    {
        $result = $this->service->test($id, $this->companyId($request));

        return $this->success($result, $result['message']);
    }

    public function setDefault(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->setDefault($id, $this->companyId($request)), 'Default courier updated');
    }

    public function stores(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->stores($id, $this->companyId($request)));
    }

    public function locations(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|string|in:city,zone,area',
            'parentId' => 'nullable|integer|min:1',
        ]);

        return $this->success($this->service->locations($id, $this->companyId($request), $data['type'], isset($data['parentId']) ? (int) $data['parentId'] : null));
    }

    public function webhook(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->webhookInfo($id, $this->companyId($request)));
    }

    public function rotateWebhook(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->webhookInfo($id, $this->companyId($request), true), 'Webhook token changed — update it in the courier panel');
    }
}
