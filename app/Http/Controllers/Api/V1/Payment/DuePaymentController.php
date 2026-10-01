<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\RecordPaymentRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Payment\DuePaymentService;
use Illuminate\Http\JsonResponse;

class DuePaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DuePaymentService $duePaymentService)
    {
    }

    /**
     * POST /payments/{payableType}/{id}
     * Record a payment against a payable (e.g. sells, purchase-orders).
     */
    public function store(RecordPaymentRequest $request, string $payableType, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        $recordedBy = $request->attributes->get('auth_user_id');

        try {
            $dto = $this->duePaymentService->recordPayment(
                $payableType,
                $id,
                $request->validated(),
                $companyId,
                $recordedBy ? (int) $recordedBy : null
            );

            return $this->success($dto->toArray(), 'Payment recorded successfully', 201);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\Exception $e) {
            \Log::error('Payment record failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to record payment', 500);
        }
    }
}
