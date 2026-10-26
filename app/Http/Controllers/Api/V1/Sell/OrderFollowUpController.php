<?php

namespace App\Http\Controllers\Api\V1\Sell;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sell\LogFollowUpRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Sell\OrderFollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderFollowUpController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OrderFollowUpService $service)
    {
    }

    /** GET /sells/{id}/follow-ups */
    public function index(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }
        try {
            return $this->success($this->service->list($id, $companyId), 'Follow-ups retrieved successfully');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\Exception $e) {
            \Log::error('Follow-up list failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to retrieve follow-ups', 500);
        }
    }

    /** POST /sells/{id}/follow-ups  { outcome, channel?, note?, nextFollowUpAt? } */
    public function store(LogFollowUpRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }
        try {
            $result = $this->service->log($id, $companyId, $request->validated());
            return $this->success($result, 'Follow-up saved', 201);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\Exception $e) {
            \Log::error('Follow-up save failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to save follow-up', 500);
        }
    }
}
