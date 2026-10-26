<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Pos\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PosController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PosService $service)
    {
    }

    public function shift(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        $filters = $request->validate(['register' => 'nullable|string|max:100']);

        try {
            return $this->success($this->service->getShift($companyId, $filters['register'] ?? null), 'Shift retrieved');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    public function openShift(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }
        $userId = $request->attributes->get('auth_user_id');

        $data = $request->validate([
            'register' => 'required|string|max:100',
            'locationId' => 'nullable|integer|min:1',
            'openingCash' => 'required|numeric|min:0',
        ]);

        try {
            return $this->success($this->service->openShift($companyId, $userId ? (int) $userId : null, $data), 'Shift opened', 201);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    public function movement(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }
        $userId = $request->attributes->get('auth_user_id');

        $data = $request->validate([
            'type' => 'required|in:in,out',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
        ]);

        try {
            return $this->success($this->service->addMovement($companyId, $userId ? (int) $userId : null, $id, $data), 'Cash movement recorded');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    public function closeShift(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        $data = $request->validate([
            'counted' => 'required|numeric|min:0',
            'note' => 'nullable|string',
        ]);

        try {
            return $this->success($this->service->closeShift($companyId, $id, $data), 'Shift closed');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    public function held(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        return $this->success($this->service->listHeld($companyId), 'Held sales retrieved');
    }

    public function storeHeld(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }
        $userId = $request->attributes->get('auth_user_id');

        $data = $request->validate([
            'label' => 'nullable|string|max:50',
            'customerId' => 'nullable|integer',
            'itemCount' => 'required|integer|min:0',
            'total' => 'required|numeric',
            'payload' => 'required|array',
        ]);

        try {
            return $this->success($this->service->createHeld($companyId, $userId ? (int) $userId : null, $data), 'Sale held', 201);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    public function destroyHeld(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $this->service->deleteHeld($companyId, $id);

            return $this->success(null, 'Held sale deleted');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    public function bestSellers(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        $filters = $request->validate(['days' => 'nullable|integer|min:0|max:3650']);
        $days = (int) ($filters['days'] ?? 90);

        $result = $this->service->bestSellers($companyId, $days);

        return $this->success($result === [] ? new \stdClass() : $result, 'Best sellers retrieved');
    }
}
