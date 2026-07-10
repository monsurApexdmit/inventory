<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CreateSubscriptionRequest;
use App\Http\Requests\Billing\RenewSubscriptionRequest;
use App\Http\Requests\Billing\UpsertBillingContactRequest;
use App\Http\Requests\Billing\UpgradeSubscriptionRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BillingService $billingService)
    {
    }

    public function plans(): JsonResponse
    {
        return $this->success($this->billingService->getPlans());
    }

    public function updatePlan(Request $request, int $id): JsonResponse
    {
        $plan = $this->billingService->updatePlan($id, $request->all());

        return $this->success($plan, 'Plan updated successfully');
    }

    public function subscription(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $subscription = $this->billingService->getSubscription($companyId);

        return $this->success($subscription ? $subscription->toArray() : ['status' => 'none']);
    }

    public function payments(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->billingService->getPayments($companyId));
    }

    public function renew(RenewSubscriptionRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $validated = $request->validated();
        $subscription = $this->billingService->renew(
            $companyId,
            $validated['subscriptionId'],
            $validated['autoRenew'] ?? null
        );

        return $this->success($subscription ? $subscription->toArray() : null);
    }

    public function cancel(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $subscriptionId = (int) $request->input('subscriptionId');
        $subscription = $this->billingService->cancel($companyId, $subscriptionId);

        return $this->success($subscription ? $subscription->toArray() : null);
    }

    public function upgrade(UpgradeSubscriptionRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $validated = $request->validated();
        $subscription = $this->billingService->upgrade($companyId, $validated['planId']);

        return $this->success($subscription ? $subscription->toArray() : null);
    }

    public function createSubscription(CreateSubscriptionRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $validated = $request->validated();
        $dto = $this->billingService->createSubscription($companyId, $validated['planId']);

        return $this->success(
            $dto->toArray(),
            'Subscription created successfully',
            201
        );
    }

    public function contact(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->billingService->getContact($companyId);

        return $this->success($dto->toArray());
    }

    public function upsertContact(UpsertBillingContactRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->billingService->upsertContact($companyId, $request->validated());

        return $this->success($dto->toArray());
    }
}
