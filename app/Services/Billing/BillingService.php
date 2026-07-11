<?php

namespace App\Services\Billing;

use App\DTOs\Billing\BillingContactDTO;
use App\DTOs\Billing\BillingContactMapper;
use App\DTOs\Billing\PaymentDTO;
use App\DTOs\Billing\PaymentMapper;
use App\DTOs\Billing\SubscriptionDTO;
use App\DTOs\Billing\SubscriptionMapper;
use App\Repositories\Contracts\IBillingContactRepository;
use App\Repositories\Contracts\IPaymentRepository;
use App\Repositories\Contracts\ISubscriptionPlanRepository;
use App\Repositories\Contracts\ISubscriptionRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingService
{
    private readonly SubscriptionMapper $subscriptionMapper;
    private readonly PaymentMapper $paymentMapper;
    private readonly BillingContactMapper $billingContactMapper;

    public function __construct(
        private readonly ISubscriptionPlanRepository $planRepository,
        private readonly ISubscriptionRepository $subscriptionRepository,
        private readonly IPaymentRepository $paymentRepository,
        private readonly IBillingContactRepository $billingContactRepository,
    ) {
        $this->subscriptionMapper = new SubscriptionMapper();
        $this->paymentMapper = new PaymentMapper();
        $this->billingContactMapper = new BillingContactMapper();
    }

    public function getPlans(): array
    {
        $plans = $this->planRepository->findAllActive();

        // Trial is assigned automatically during signup; it is not a
        // purchasable plan and should not appear beside the paid tiers.
        $plans = array_values(array_filter(
            $plans,
            fn (array $plan) => strcasecmp($plan['name'], 'Trial') !== 0
        ));

        return array_map(fn ($plan) => $this->formatPlan($plan), $plans);
    }

    public function updatePlan(int $planId, array $data): array
    {
        $plan = $this->planRepository->findById($planId);

        if (!$plan) {
            throw new HttpException(404, 'Plan not found.');
        }

        $updateData = [];
        if (isset($data['name']))        $updateData['name'] = $data['name'];
        if (isset($data['description'])) $updateData['description'] = $data['description'];
        if (isset($data['price']))       $updateData['price'] = (int) round($data['price'] * 100);
        if (isset($data['maxUsers']))    $updateData['max_users'] = (int) $data['maxUsers'];
        if (isset($data['maxProducts'])) $updateData['max_products'] = (int) $data['maxProducts'];
        if (isset($data['maxBranches'])) $updateData['max_branches'] = (int) $data['maxBranches'];
        if (isset($data['features']))    $updateData['features'] = json_encode($data['features']);
        if (isset($data['isFeatured']))  $updateData['is_featured'] = (bool) $data['isFeatured'];

        $updated = $this->planRepository->update($planId, $updateData);

        return $this->formatPlan($updated->toArray());
    }

    private function formatPlan(array $plan): array
    {
        return [
            'id' => $plan['id'],
            'name' => $plan['name'],
            'description' => $plan['description'],
            'price' => (int) $plan['price'],
            'billingPeriod' => $plan['billing_period'],
            'maxUsers' => (int) $plan['max_users'],
            'maxProducts' => (int) $plan['max_products'],
            'maxBranches' => (int) $plan['max_branches'],
            'features' => json_decode($plan['features'], true) ?? [],
            'isFeatured' => (bool) $plan['is_featured'],
        ];
    }

    public function getSubscription(int $companyId): ?SubscriptionDTO
    {
        $subscription = $this->subscriptionRepository->findByCompanyId($companyId);

        if (!$subscription) {
            return null;
        }

        return $this->subscriptionMapper->toDTO($subscription);
    }

    public function getPayments(int $companyId): array
    {
        $paymentsPaginated = $this->paymentRepository->findByCompanyId($companyId);

        $payments = $paymentsPaginated->getCollection()->map(
            fn ($payment) => $this->paymentMapper->toDTO($payment)->toArray()
        )->toArray();

        return [
            'payments' => $payments,
            'total' => $paymentsPaginated->total(),
            'page' => $paymentsPaginated->currentPage(),
            'limit' => $paymentsPaginated->perPage(),
        ];
    }

    public function renew(int $companyId, int $subscriptionId, ?bool $autoRenew = null): ?SubscriptionDTO
    {
        $subscription = $this->subscriptionRepository->findByCompanyId($companyId);

        if (!$subscription || $subscription->id !== $subscriptionId) {
            throw new HttpException(404, 'Subscription not found');
        }

        $plan = $subscription->plan;

        // Calculate billing period duration based on plan's billing_period
        $days = $plan->billing_period === 'yearly' ? 365 : 30;

        $updateData = [
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($days),
            'next_billing_date' => now()->addDays($days),
        ];

        if ($autoRenew !== null) {
            $updateData['auto_renew'] = $autoRenew;
        }

        $this->subscriptionRepository->update($subscriptionId, $updateData);

        // Create a payment record for the renewal
        $this->paymentRepository->create([
            'subscription_id' => $subscriptionId,
            'company_id' => $companyId,
            'amount' => (int) $plan->price,
            'status' => 'completed',
            'payment_method' => 'card',
            'payment_date' => now(),
            'invoice_number' => 'INV-' . strtoupper(uniqid()),
            'description' => "Renewal of {$plan->name} plan subscription",
        ]);

        return $this->getSubscription($companyId);
    }

    public function cancel(int $companyId, int $subscriptionId): ?SubscriptionDTO
    {
        $subscription = $this->subscriptionRepository->findByCompanyId($companyId);

        if (!$subscription || $subscription->id !== $subscriptionId) {
            throw new HttpException(404, 'Subscription not found');
        }

        $this->subscriptionRepository->update($subscriptionId, [
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'auto_renew' => false,
        ]);

        return $this->getSubscription($companyId);
    }

    public function upgrade(int $companyId, int $newPlanId): ?SubscriptionDTO
    {
        $subscription = $this->subscriptionRepository->findByCompanyId($companyId);

        if (!$subscription) {
            throw new HttpException(404, 'No active subscription');
        }

        $newPlan = $this->planRepository->findById($newPlanId);
        if (!$newPlan) {
            throw new HttpException(404, 'Plan not found');
        }

        // Guard against upgrading to the same plan
        if ($subscription->plan_id === $newPlanId) {
            throw new HttpException(400, 'Cannot upgrade to the same plan');
        }

        $this->subscriptionRepository->update($subscription->id, [
            'plan_id' => $newPlanId,
            'status' => 'active',
        ]);

        // Create a payment record for the upgrade
        $this->paymentRepository->create([
            'subscription_id' => $subscription->id,
            'company_id' => $companyId,
            'amount' => (int) $newPlan['price'],
            'status' => 'completed',
            'payment_method' => 'card',
            'payment_date' => now(),
            'invoice_number' => 'INV-' . strtoupper(uniqid()),
            'description' => "Upgrade from {$subscription->plan->name} to {$newPlan['name']} plan",
        ]);

        return $this->getSubscription($companyId);
    }

    public function createSubscription(int $companyId, int $planId): ?SubscriptionDTO
    {
        $plan = $this->planRepository->findById($planId);
        if (!$plan) {
            throw new HttpException(404, 'Plan not found');
        }

        // Calculate billing period duration based on plan's billing_period
        $days = $plan['billing_period'] === 'yearly' ? 365 : 30;

        $subscription = $this->subscriptionRepository->create([
            'company_id' => $companyId,
            'plan_id' => $planId,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($days),
            'next_billing_date' => now()->addDays($days),
            'auto_renew' => true,
        ]);

        // Create a payment record for the initial subscription
        $this->paymentRepository->create([
            'subscription_id' => $subscription->id,
            'company_id' => $companyId,
            'amount' => (int) $plan['price'],
            'status' => 'completed',
            'payment_method' => 'card',
            'payment_date' => now(),
            'invoice_number' => 'INV-' . strtoupper(uniqid()),
            'description' => "Subscription to {$plan['name']} plan",
        ]);

        return $this->subscriptionMapper->toDTO($subscription);
    }

    public function getContact(int $companyId): BillingContactDTO
    {
        $contact = $this->billingContactRepository->findByCompanyId($companyId);

        if (!$contact) {
            // Return default empty contact DTO for new companies
            return new BillingContactDTO(
                id: 0,
                companyId: $companyId,
                email: '',
                phone: null,
                address: null,
                city: null,
                state: null,
                zipCode: null,
                country: null,
                taxId: null,
                taxIdType: null,
                isDefault: false,
                createdAt: now()->toIso8601String(),
                updatedAt: now()->toIso8601String(),
            );
        }

        return $this->billingContactMapper->toDTO($contact);
    }

    public function upsertContact(int $companyId, array $data): BillingContactDTO
    {
        $contact = $this->billingContactRepository->upsert($companyId, $data);

        return $this->billingContactMapper->toDTO($contact);
    }
}
