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
use App\Models\Company;
use App\Models\Invitation;
use App\Models\Payment;
use App\Models\Sell;
use App\Services\Company\PlanLimitService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingService
{
    /** Tax added on top of the plan price at checkout. */
    public const TAX_RATE = 0.10;

    public const TRIAL_DAYS = 10;

    public const PAYMENT_METHODS = ['card', 'bkash', 'bank'];

    private readonly SubscriptionMapper $subscriptionMapper;
    private readonly PaymentMapper $paymentMapper;
    private readonly BillingContactMapper $billingContactMapper;

    public function __construct(
        private readonly ISubscriptionPlanRepository $planRepository,
        private readonly ISubscriptionRepository $subscriptionRepository,
        private readonly IPaymentRepository $paymentRepository,
        private readonly IBillingContactRepository $billingContactRepository,
        private readonly PlanLimitService $planLimitService,
    ) {
        $this->subscriptionMapper = new SubscriptionMapper();
        $this->paymentMapper = new PaymentMapper();
        $this->billingContactMapper = new BillingContactMapper();
    }

    public function getPlans(): array
    {
        $plans = $this->planRepository->findAllActive();
        usort($plans, fn ($a, $b) => ((int) $a['price'] <=> (int) $b['price']) ?: ((int) $a['id'] <=> (int) $b['id']));

        return array_map(fn ($plan) => $this->formatPlan($plan), $plans);
    }

    public function updatePlan(int $planId, array $data): array
    {
        $plan = $this->planRepository->findById($planId);

        if (!$plan) {
            throw new HttpException(404, 'Plan not found.');
        }

        if (isset($data['name']) && trim((string) $data['name']) === '') {
            throw new HttpException(422, 'Plan name is required.');
        }
        if (isset($data['price']) && (!is_numeric($data['price']) || $data['price'] < 0)) {
            throw new HttpException(422, 'Price must be zero or more.');
        }
        foreach (['maxUsers', 'maxProducts', 'maxBranches'] as $k) {
            if (isset($data[$k]) && (!is_numeric($data[$k]) || (int) $data[$k] < 0)) {
                throw new HttpException(422, 'Limits must be whole numbers (0 or more).');
            }
        }

        if (isset($data['name']) && \App\Models\SubscriptionPlan::where('id', '!=', $planId)->whereRaw('LOWER(name) = ?', [strtolower(trim($data['name']))])->exists()) {
            throw new HttpException(422, 'Another plan already uses this name.');
        }

        $updateData = [];
        if (isset($data['name']))        $updateData['name'] = trim($data['name']);
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
            'priceYearly' => (int) $plan['price'] * 10, // 2 months free
            'taxRate' => self::TAX_RATE,
            'isTrial' => (int) $plan['price'] === 0,
            'billingPeriod' => $plan['billing_period'],
            'maxUsers' => (int) $plan['max_users'],
            'maxProducts' => (int) $plan['max_products'],
            'maxBranches' => (int) $plan['max_branches'],
            'features' => json_decode($plan['features'] ?? '[]', true) ?? [],
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

    public function getPayments(int $companyId, int $page = 1, int $limit = 15): array
    {
        $limit = max(1, min(100, $limit));
        $this->backfillTrialPayment($companyId);
        $paymentsPaginated = Payment::where('company_id', $companyId)
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', max(1, $page));

        $payments = $paymentsPaginated->getCollection()->map(
            fn ($payment) => $this->paymentMapper->toDTO($payment)->toArray()
        )->values()->toArray();

        return [
            'payments' => $payments,
            'total' => $paymentsPaginated->total(),
            'page' => $paymentsPaginated->currentPage(),
            'limit' => $paymentsPaginated->perPage(),
        ];
    }

    /**
     * Usage counters versus the current plan's limits.
     * Team members count active users plus pending invitations (seats).
     */
    public function getUsage(int $companyId): array
    {
        $limits = $this->planLimitService->getLimits($companyId);
        $subscription = $this->subscriptionRepository->findByCompanyId($companyId);

        $pendingInvites = Invitation::where('company_id', $companyId)->where('status', 'pending')->count();
        $salesThisMonth = Sell::where('company_id', $companyId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'cancelled');
            })
            ->count();

        return [
            'plan' => $limits['plan'],
            'planName' => $limits['plan']['name'] ?? ($subscription?->plan?->name),
            'teamMembers' => ['used' => $limits['currentUsers'] + $pendingInvites, 'limit' => (int) $limits['maxUsers']],
            'products' => ['used' => $limits['currentProducts'], 'limit' => (int) $limits['maxProducts']],
            'branches' => ['used' => $limits['currentBranches'], 'limit' => (int) $limits['maxBranches']],
            'salesThisMonth' => $salesThisMonth,
            'unlimitedThreshold' => 999999,
        ];
    }

    public function renew(int $companyId, int $subscriptionId, ?bool $autoRenew = null, array $checkout = []): ?SubscriptionDTO
    {
        $subscription = $this->subscriptionRepository->findByCompanyId($companyId);

        if (!$subscription || $subscription->id !== $subscriptionId) {
            throw new HttpException(404, 'Subscription not found');
        }

        $plan = $subscription->plan;

        if ((int) $plan->price === 0) {
            throw new HttpException(400, 'Trial subscriptions cannot be renewed. Choose a paid plan instead.');
        }

        $period = $this->resolvePeriod($checkout['billingPeriod'] ?? $subscription->billing_period ?? $plan->billing_period);
        $days = $period === 'yearly' ? 365 : 30;

        DB::transaction(function () use ($subscription, $subscriptionId, $plan, $companyId, $period, $days, $autoRenew, $checkout) {
            $updateData = [
                'status' => 'active',
                'billing_period' => $period,
                'current_period_start' => now(),
                'current_period_end' => now()->addDays($days),
                'next_billing_date' => now()->addDays($days),
                'cancelled_at' => null,
                'cancel_reason' => null,
            ];

            if ($autoRenew !== null) {
                $updateData['auto_renew'] = $autoRenew;
            } elseif ($subscription->status !== 'active') {
                $updateData['auto_renew'] = true;
            }

            $this->subscriptionRepository->update($subscriptionId, $updateData);

            $this->recordPayment($subscriptionId, $companyId, $plan, $period, $checkout, "Renewal of {$plan->name} plan subscription");
        });

        return $this->getSubscription($companyId);
    }

    public function setAutoRenew(int $companyId, int $subscriptionId, bool $autoRenew): ?SubscriptionDTO
    {
        $subscription = $this->subscriptionRepository->findByCompanyId($companyId);

        if (!$subscription || $subscription->id !== $subscriptionId) {
            throw new HttpException(404, 'Subscription not found');
        }
        if ((int) $subscription->plan?->price === 0) {
            throw new HttpException(400, 'Auto-renewal applies to paid plans only.');
        }
        if ($subscription->status !== 'active') {
            throw new HttpException(400, 'Reactivate your subscription first.');
        }

        $this->subscriptionRepository->update($subscriptionId, ['auto_renew' => $autoRenew]);

        return $this->getSubscription($companyId);
    }

    public function cancel(int $companyId, int $subscriptionId, ?string $reason = null): ?SubscriptionDTO
    {
        $subscription = $this->subscriptionRepository->findByCompanyId($companyId);

        if (!$subscription || $subscription->id !== $subscriptionId) {
            throw new HttpException(404, 'Subscription not found');
        }

        $this->subscriptionRepository->update($subscriptionId, [
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_reason' => $reason ? mb_substr($reason, 0, 255) : null,
            'auto_renew' => false,
        ]);

        return $this->getSubscription($companyId);
    }

    public function upgrade(int $companyId, int $newPlanId, array $checkout = []): ?SubscriptionDTO
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

        if ((int) $newPlan->price === 0) {
            $this->assertTrialAvailable($companyId);
        }

        $this->ensureTrialPayment($subscription);

        $period = $this->resolvePeriod($checkout['billingPeriod'] ?? $newPlan->billing_period);
        $days = $period === 'yearly' ? 365 : 30;
        $oldName = $subscription->plan?->name;

        DB::transaction(function () use ($subscription, $newPlanId, $newPlan, $companyId, $period, $days, $checkout, $oldName) {
            $this->subscriptionRepository->update($subscription->id, [
                'plan_id' => $newPlanId,
                'status' => 'active',
                'billing_period' => $period,
                'current_period_start' => now(),
                'current_period_end' => now()->addDays($days),
                'next_billing_date' => now()->addDays($days),
                'auto_renew' => true,
                'cancelled_at' => null,
                'cancel_reason' => null,
            ]);

            $this->recordPayment($subscription->id, $companyId, $newPlan, $period, $checkout, "Upgrade from {$oldName} to {$newPlan->name} plan");
        });

        return $this->getSubscription($companyId);
    }

    public function createSubscription(int $companyId, int $planId, array $checkout = []): ?SubscriptionDTO
    {
        $plan = $this->planRepository->findById($planId);
        if (!$plan) {
            throw new HttpException(404, 'Plan not found');
        }

        $isTrial = (int) $plan->price === 0;

        if ($isTrial) {
            $this->assertTrialAvailable($companyId);
        }

        $period = $isTrial ? 'monthly' : $this->resolvePeriod($checkout['billingPeriod'] ?? $plan->billing_period);
        $days = $isTrial ? self::TRIAL_DAYS : ($period === 'yearly' ? 365 : 30);

        $subscription = DB::transaction(function () use ($companyId, $planId, $plan, $isTrial, $period, $days, $checkout) {
            // A new subscription supersedes any previous live one.
            \App\Models\Subscription::where('company_id', $companyId)
                ->whereIn('status', ['active', 'trialing', 'cancelled'])
                ->update(['status' => 'expired']);

            $subscription = $this->subscriptionRepository->create([
                'company_id' => $companyId,
                'plan_id' => $planId,
                'status' => $isTrial ? 'trialing' : 'active',
                'billing_period' => $period,
                'current_period_start' => now(),
                'current_period_end' => now()->addDays($days),
                'next_billing_date' => now()->addDays($days),
                'auto_renew' => !$isTrial,
            ]);

            if (!$isTrial) {
                $this->recordPayment($subscription->id, $companyId, $plan, $period, $checkout, "Subscription to {$plan->name} plan");
            } else {
                $this->ensureTrialPayment($subscription->load('plan'));
            }

            return $subscription;
        });

        return $this->subscriptionMapper->toDTO($subscription->load('plan'));
    }

    /**
     * The free trial is locked only once a PAID plan has existed for the company
     * (a mere trial does not count). A still-running trial cannot be restarted.
     */
    private function assertTrialAvailable(int $companyId): void
    {
        $paidPayment = Payment::where('company_id', $companyId)->where('amount', '>', 0)->exists();
        $paidSub = \App\Models\Subscription::where('company_id', $companyId)
            ->whereHas('plan', fn ($q) => $q->where('price', '>', 0))->exists();
        if ($paidPayment || $paidSub) {
            throw new HttpException(422, 'The free trial has already been used.');
        }

        $live = \App\Models\Subscription::where('company_id', $companyId)
            ->whereIn('status', ['active', 'trialing'])
            ->where('current_period_end', '>', now())->exists();
        if ($live) {
            throw new HttpException(422, 'Your free trial is still active.');
        }
    }

    /** Keep a permanent "Free trial" row (amount 0, no invoice) in the payment history. */
    private function ensureTrialPayment($subscription): void
    {
        $plan = $subscription->plan;
        if (!$plan || (int) $plan->price !== 0) {
            return;
        }
        if (Payment::where('subscription_id', $subscription->id)->where('amount', 0)->exists()) {
            return;
        }
        $this->paymentRepository->create([
            'subscription_id' => $subscription->id,
            'company_id' => $subscription->company_id,
            'amount' => 0,
            'subtotal' => 0,
            'tax_amount' => 0,
            'status' => 'completed',
            'payment_method' => 'free',
            'payment_date' => $subscription->current_period_start ?? $subscription->created_at ?? now(),
            'invoice_number' => null,
            'description' => 'Free trial',
            'plan_name' => $plan->name,
            'billing_period' => 'monthly',
        ]);
    }

    private function backfillTrialPayment(int $companyId): void
    {
        $subscription = $this->subscriptionRepository->findByCompanyId($companyId);
        if ($subscription) {
            $this->ensureTrialPayment($subscription);
        }
    }

    /** Change the billing period of the current paid subscription without changing the plan. */
    public function changeBillingPeriod(int $companyId, int $subscriptionId, string $period): ?SubscriptionDTO
    {
        $subscription = $this->subscriptionRepository->findByCompanyId($companyId);

        if (!$subscription || $subscription->id !== $subscriptionId) {
            throw new HttpException(404, 'Subscription not found');
        }
        if ((int) $subscription->plan?->price === 0) {
            throw new HttpException(400, 'Billing period applies to paid plans only.');
        }
        if ($subscription->status !== 'active') {
            throw new HttpException(400, 'Reactivate your subscription first.');
        }

        // Takes effect from the next renewal; the paid period already running is untouched.
        $this->subscriptionRepository->update($subscriptionId, ['billing_period' => $this->resolvePeriod($period)]);

        return $this->getSubscription($companyId);
    }

    /**
     * Build the printable invoice (HTML, "Save as PDF" from the browser) for a payment.
     */
    public function getInvoice(int $companyId, int $paymentId): array
    {
        $payment = Payment::where('company_id', $companyId)->where('id', $paymentId)->first();
        if (!$payment) {
            throw new HttpException(404, 'Invoice not found');
        }

        $company = Company::find($companyId);
        $contact = $this->billingContactRepository->findByCompanyId($companyId);
        $cur = strtoupper((string) ($company?->currency ?: 'USD'));
        $money = fn (?int $cents) => $cur . ' ' . number_format(((int) $cents) / 100, 2);

        $total = (int) $payment->amount;
        $tax = $payment->tax_amount !== null ? (int) $payment->tax_amount : 0;
        $subtotal = $payment->subtotal !== null ? (int) $payment->subtotal : $total - $tax;
        $number = $payment->invoice_number ?: ('PAY-' . $payment->id);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $period = $payment->billing_period ? ucfirst($payment->billing_period) : '—';
        $methods = ['card' => 'Card', 'bkash' => 'bKash', 'bank' => 'Bank transfer'];
        $method = $methods[$payment->payment_method] ?? ($payment->payment_method ?: '—');
        $status = $payment->status === 'completed' ? 'Paid' : ucfirst((string) $payment->status);
        $date = ($payment->payment_date ?? $payment->created_at)?->format('d M Y');
        $billTo = $e($company?->name ?? '');
        $billEmail = $e($payment->billing_email ?: ($contact?->email ?? ''));
        $addr = $e(trim(implode(', ', array_filter([$contact?->address, $contact?->city, $contact?->country]))));
        $taxId = $contact?->tax_id ? '<div>Tax ID: ' . $e($contact->tax_id) . '</div>' : '';
        $planName = $e($payment->plan_name ?: ($payment->description ?? 'Subscription'));
        $desc = $e($payment->description ?? $planName);

        $html = <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Invoice {$e($number)}</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;color:#0E1525;margin:40px;max-width:760px}
h1{margin:0 0 4px;font-size:28px}.muted{color:#5B6478;font-size:13px}
table{width:100%;border-collapse:collapse;margin-top:28px}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #E3E6EE;font-size:14px}
th{background:#F3F4F7;font-size:12px;text-transform:uppercase;letter-spacing:.04em}td.r,th.r{text-align:right}
.tot td{font-weight:700;border-bottom:0}.badge{display:inline-block;padding:2px 10px;border-radius:999px;background:#DDF3EF;color:#0B5D57;font-size:12px;font-weight:700}
.row{display:flex;justify-content:space-between;gap:24px;margin-top:24px}@media print{.noprint{display:none}body{margin:16px}}
</style></head><body>
<button class="noprint" onclick="window.print()" style="float:right;padding:8px 14px">Print / Save as PDF</button>
<h1>Invoice</h1><div class="muted">{$e($number)}</div>
<div class="row"><div><strong>Billed to</strong><div>{$billTo}</div><div class="muted">{$billEmail}</div><div class="muted">{$addr}</div><div class="muted">{$taxId}</div></div>
<div style="text-align:right"><div><strong>Date</strong> {$e($date)}</div><div><strong>Method</strong> {$e($method)}</div><div style="margin-top:6px"><span class="badge">{$e($status)}</span></div></div></div>
<table><thead><tr><th>Description</th><th>Plan</th><th>Period</th><th class="r">Amount</th></tr></thead>
<tbody><tr><td>{$desc}</td><td>{$planName}</td><td>{$e($period)}</td><td class="r">{$e($money($subtotal))}</td></tr>
<tr><td colspan="3" class="r">Tax</td><td class="r">{$e($money($tax))}</td></tr>
<tr class="tot"><td colspan="3" class="r">Total</td><td class="r">{$e($money($total))}</td></tr></tbody></table>
</body></html>
HTML;

        return [
            'filename' => $number . '.html',
            'invoiceNumber' => $number,
            'contentType' => 'text/html',
            'html' => $html,
        ];
    }

    private function resolvePeriod(?string $period): string
    {
        return $period === 'yearly' ? 'yearly' : 'monthly';
    }

    /**
     * Creates the payment/invoice row for a plan purchase. Amounts are stored in cents.
     * Yearly = 10 x monthly (2 months free); tax is added on top.
     */
    private function recordPayment(int $subscriptionId, int $companyId, $plan, string $period, array $checkout, string $description): Payment
    {
        $base = (int) $plan->price;
        $subtotal = $period === 'yearly' ? $base * 10 : $base;
        $tax = (int) round($subtotal * self::TAX_RATE);
        $method = $checkout['paymentMethod'] ?? 'card';
        if (!in_array($method, self::PAYMENT_METHODS, true)) {
            $method = 'card';
        }

        return $this->paymentRepository->create([
            'subscription_id' => $subscriptionId,
            'company_id' => $companyId,
            'amount' => $subtotal + $tax,
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'status' => 'completed',
            'payment_method' => $method,
            'payment_date' => now(),
            'invoice_number' => 'INV-' . strtoupper(uniqid()),
            'description' => $description,
            'plan_name' => $plan->name,
            'billing_period' => $period,
            'billing_email' => $checkout['billingEmail'] ?? null,
        ]);
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
