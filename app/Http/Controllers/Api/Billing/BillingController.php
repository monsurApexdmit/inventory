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
        // Plans are global to the platform: only super admins may edit them.
        if (!$request->attributes->get('auth_is_super_admin')) {
            return $this->error('Only platform administrators can edit plans.', 403);
        }

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

        return $this->success($this->billingService->getPayments(
            $companyId,
            (int) $request->query('page', 1),
            (int) $request->query('limit', 15)
        ));
    }

    public function usage(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->billingService->getUsage($companyId));
    }

    public function invoice(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->billingService->getInvoice($companyId, $id));
    }

    public function autoRenew(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscriptionId' => 'required|integer|exists:subscriptions,id',
            'autoRenew' => 'required|boolean',
        ]);
        $companyId = (int) $request->attributes->get('auth_company_id');
        $sub = $this->billingService->setAutoRenew($companyId, (int) $data['subscriptionId'], (bool) $data['autoRenew']);

        return $this->success($sub ? $sub->toArray() : null);
    }

    public function billingPeriod(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscriptionId' => 'required|integer|exists:subscriptions,id',
            'billingPeriod' => 'required|in:monthly,yearly',
        ]);
        $companyId = (int) $request->attributes->get('auth_company_id');
        $sub = $this->billingService->changeBillingPeriod($companyId, (int) $data['subscriptionId'], $data['billingPeriod']);

        return $this->success($sub ? $sub->toArray() : null);
    }

    public function renew(RenewSubscriptionRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $validated = $request->validated();
        $subscription = $this->billingService->renew(
            $companyId,
            $validated['subscriptionId'],
            $validated['autoRenew'] ?? null,
            $this->checkoutOptions($validated)
        );

        return $this->success($subscription ? $subscription->toArray() : null);
    }

    public function cancel(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $subscriptionId = (int) $request->input('subscriptionId');
        $subscription = $this->billingService->cancel($companyId, $subscriptionId, $request->input('reason'));

        return $this->success($subscription ? $subscription->toArray() : null);
    }

    public function upgrade(UpgradeSubscriptionRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $validated = $request->validated();
        $subscription = $this->billingService->upgrade($companyId, $validated['planId'], $this->checkoutOptions($validated));

        return $this->success($subscription ? $subscription->toArray() : null);
    }

    public function createSubscription(CreateSubscriptionRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $validated = $request->validated();
        $dto = $this->billingService->createSubscription($companyId, $validated['planId'], $this->checkoutOptions($validated));

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

    private function checkoutOptions(array $validated): array
    {
        return array_filter([
            'billingPeriod' => $validated['billingPeriod'] ?? null,
            'paymentMethod' => $validated['paymentMethod'] ?? null,
            'billingEmail' => $validated['billingEmail'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
