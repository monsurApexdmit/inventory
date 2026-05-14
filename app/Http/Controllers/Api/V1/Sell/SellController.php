<?php

namespace App\Http\Controllers\Api\V1\Sell;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sell\CreateSellRequest;
use App\Http\Requests\Sell\UpdateSellRequest;
use App\Http\Requests\Sell\UpdateStatusRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Sell\SellService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SellService $sellService)
    {
    }

    /**
     * GET /sells
     * List all sells with pagination and filters
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $filters = $request->query();
            $result = $this->sellService->list($companyId, $filters);

            // Check if paginated response or limit response
            if (isset($result['per_page'])) {
                return $this->success($result['data'], 'Sells retrieved successfully', 200, [
                    'pagination' => [
                        'page' => $result['current_page'],
                        'per_page' => $result['per_page'],
                        'total' => $result['total'],
                        'last_page' => $result['last_page'],
                    ],
                ]);
            }

            return $this->success($result['data'], 'Sells retrieved successfully');
        } catch (\Exception $e) {
            \Log::error('Sell list failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to retrieve sells', 500);
        }
    }

    /**
     * GET /sells/stats
     * Get sell statistics
     */
    public function stats(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $stats = $this->sellService->getStats($companyId);
            return $this->success($stats, 'Statistics retrieved successfully');
        } catch (\Exception $e) {
            \Log::error('Sell stats failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to retrieve statistics', 500);
        }
    }

    public function weeklyOrders(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $data = $this->sellService->getWeeklyOrders($companyId);
            return $this->success($data, 'Weekly orders retrieved successfully');
        } catch (\Exception $e) {
            \Log::error('Weekly orders failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to retrieve weekly orders', 500);
        }
    }

    public function monthlyRevenue(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $data = $this->sellService->getMonthlyRevenue($companyId);
            return $this->success($data, 'Monthly revenue retrieved successfully');
        } catch (\Exception $e) {
            \Log::error('Monthly revenue failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to retrieve monthly revenue', 500);
        }
    }

    /**
     * GET /sells/:id
     * Get a single sell
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $dto = $this->sellService->get($id, $companyId);
            return $this->success($dto->toArray(), 'Sell fetched successfully');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\Exception $e) {
            \Log::error('Sell fetch failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to retrieve sell', 500);
        }
    }

    /**
     * GET /sells/invoice/:invoiceNo
     * Get sell by invoice number
     */
    public function getByInvoice(Request $request, string $invoiceNo): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        // Strip leading # if present (in case it was included in the URL)
        $invoiceNo = ltrim($invoiceNo, '#');

        try {
            $dto = $this->sellService->getByInvoice($invoiceNo, $companyId);
            return $this->success($dto->toArray(), 'Sell fetched successfully');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\Exception $e) {
            \Log::error('Sell fetch by invoice failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to retrieve sell', 500);
        }
    }

    /**
     * POST /sells
     * Create a new sell
     */
    public function store(CreateSellRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $dto = $this->sellService->create($companyId, $request->validated());
            return $this->success($dto->toArray(), 'Sell created successfully', 201);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\Exception $e) {
            \Log::error('Sell creation failed', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->error('Failed to create sell', 500);
        }
    }

    /**
     * PUT /sells/:id
     * Update a sell (partial update, no stock changes)
     */
    public function update(UpdateSellRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $dto = $this->sellService->update($id, $companyId, $request->validated());
            return $this->success($dto->toArray(), 'Sell updated successfully');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\Exception $e) {
            \Log::error('Sell update failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to update sell', 500);
        }
    }

    /**
     * PATCH /sells/:id/status
     * Update sell status
     */
    public function updateStatus(UpdateStatusRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $dto = $this->sellService->updateStatus($id, $companyId, $request->validated()['status']);
            return $this->success($dto->toArray(), 'Status updated successfully');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\Exception $e) {
            \Log::error('Sell status update failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to update status', 500);
        }
    }

    /**
     * DELETE /sells/:id
     * Delete a sell (soft delete + stock restoration)
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $this->sellService->delete($id, $companyId);
            return $this->success(['message' => 'Sell deleted successfully']);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\Exception $e) {
            \Log::error('Sell deletion failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to delete sell', 500);
        }
    }
}
