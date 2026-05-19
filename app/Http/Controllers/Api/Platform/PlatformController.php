<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Company;
use App\Models\SaasUser;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlatformController extends Controller
{
    use ApiResponse;

    // ── Stats ────────────────────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        $totalCompanies  = Company::count();
        $activeCompanies = Company::where('status', 'active')->count();
        $trialCompanies  = Company::where('status', 'trial')->count();
        $inactiveCompanies = Company::where('status', 'inactive')->count();

        $activeSubscriptions = Subscription::where('status', 'active')->count();
        $mrr = Subscription::where('status', 'active')
            ->join('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
            ->sum('subscription_plans.price') / 100;

        $totalUsers  = SaasUser::whereNotNull('company_id')->count();
        $totalPlans  = SubscriptionPlan::count();
        $activePlans = SubscriptionPlan::where('is_active', true)->count();

        return $this->success([
            'totalCompanies'     => $totalCompanies,
            'activeCompanies'    => $activeCompanies,
            'trialCompanies'     => $trialCompanies,
            'inactiveCompanies'  => $inactiveCompanies,
            'activeSubscriptions'=> $activeSubscriptions,
            'mrr'                => round($mrr, 2),
            'totalUsers'         => $totalUsers,
            'totalPlans'         => $totalPlans,
            'activePlans'        => $activePlans,
        ]);
    }

    // ── Companies ────────────────────────────────────────────────────────────

    public function listCompanies(Request $request): JsonResponse
    {
        $query = Company::with(['subscriptions' => fn($q) => $q->latest()->limit(1), 'subscriptions.plan'])
            ->withCount('saasUsers');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = (int) ($request->query('per_page') ?? 20);
        $companies = $query->orderByDesc('created_at')->paginate($perPage);

        $data = collect($companies->items())->map(fn($c) => $this->formatCompany($c))->values()->all();

        return $this->success($data, 'Success', 200, [
            'pagination' => [
                'total'        => $companies->total(),
                'per_page'     => $companies->perPage(),
                'current_page' => $companies->currentPage(),
                'last_page'    => $companies->lastPage(),
            ],
        ]);
    }

    public function getCompany(int $id): JsonResponse
    {
        $company = Company::with([
            'subscriptions' => fn($q) => $q->latest()->limit(1),
            'subscriptions.plan',
            'saasUsers',
        ])->findOrFail($id);

        return $this->success($this->formatCompanyDetail($company));
    }

    public function updateCompanyStatus(Request $request, int $id): JsonResponse
    {
        $status = $request->input('status');

        if (!in_array($status, ['active', 'inactive', 'trial', 'suspended'], true)) {
            throw new HttpException(422, 'Invalid status. Allowed: active, inactive, trial, suspended');
        }

        $company = Company::findOrFail($id);
        $company->update(['status' => $status]);

        // Also deactivate/reactivate all saas users of this company
        if ($status === 'inactive' || $status === 'suspended') {
            SaasUser::where('company_id', $id)
                ->where('role', '!=', 'super_admin')
                ->update(['status' => 'inactive']);
        } elseif ($status === 'active') {
            SaasUser::where('company_id', $id)
                ->where('status', 'inactive')
                ->update(['status' => 'active']);
        }

        return $this->success(['id' => $company->id, 'status' => $company->status], 'Company status updated.');
    }

    public function listCompanyUsers(int $id): JsonResponse
    {
        Company::findOrFail($id);

        $users = SaasUser::where('company_id', $id)
            ->orderBy('role')
            ->orderBy('full_name')
            ->get()
            ->map(fn($u) => [
                'id'         => $u->id,
                'fullName'   => $u->full_name,
                'email'      => $u->email,
                'role'       => $u->role,
                'status'     => $u->status,
                'joinedDate' => $u->joined_date?->toIso8601String(),
                'lastLogin'  => $u->last_login?->toIso8601String(),
            ]);

        return $this->success($users->values()->all());
    }

    // ── Plans ────────────────────────────────────────────────────────────────

    public function listPlans(): JsonResponse
    {
        $plans = SubscriptionPlan::orderBy('price')->get()->map(fn($p) => $this->formatPlan($p));
        return $this->success($plans->values()->all());
    }

    public function createPlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'price'       => 'required|numeric|min:0',
            'maxUsers'    => 'required|integer|min:1',
            'maxProducts' => 'required|integer|min:1',
            'maxBranches' => 'required|integer|min:1',
            'features'    => 'nullable|array',
            'isFeatured'  => 'nullable|boolean',
            'isActive'    => 'nullable|boolean',
        ]);

        $plan = SubscriptionPlan::create([
            'name'           => $data['name'],
            'description'    => $data['description'] ?? null,
            'price'          => (int) round($data['price'] * 100),
            'billing_period' => 'monthly',
            'max_users'      => $data['maxUsers'],
            'max_products'   => $data['maxProducts'],
            'max_branches'   => $data['maxBranches'],
            'features'       => json_encode($data['features'] ?? []),
            'is_featured'    => $data['isFeatured'] ?? false,
            'is_active'      => $data['isActive'] ?? true,
        ]);

        return $this->success($this->formatPlan($plan), 'Plan created successfully.', 201);
    }

    public function updatePlan(Request $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'description' => 'sometimes|nullable|string',
            'price'       => 'sometimes|numeric|min:0',
            'maxUsers'    => 'sometimes|integer|min:1',
            'maxProducts' => 'sometimes|integer|min:1',
            'maxBranches' => 'sometimes|integer|min:1',
            'features'    => 'sometimes|array',
            'isFeatured'  => 'sometimes|boolean',
            'isActive'    => 'sometimes|boolean',
        ]);

        $update = [];
        if (isset($data['name']))        $update['name'] = $data['name'];
        if (isset($data['description'])) $update['description'] = $data['description'];
        if (isset($data['price']))       $update['price'] = (int) round($data['price'] * 100);
        if (isset($data['maxUsers']))    $update['max_users'] = $data['maxUsers'];
        if (isset($data['maxProducts'])) $update['max_products'] = $data['maxProducts'];
        if (isset($data['maxBranches'])) $update['max_branches'] = $data['maxBranches'];
        if (isset($data['features']))    $update['features'] = json_encode($data['features']);
        if (isset($data['isFeatured']))  $update['is_featured'] = $data['isFeatured'];
        if (isset($data['isActive']))    $update['is_active'] = $data['isActive'];

        $plan->update($update);

        return $this->success($this->formatPlan($plan->fresh()), 'Plan updated successfully.');
    }

    public function togglePlanStatus(int $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);
        $plan->update(['is_active' => !$plan->is_active]);

        return $this->success(['id' => $plan->id, 'isActive' => $plan->is_active], 'Plan status toggled.');
    }

    // ── Subscriptions ────────────────────────────────────────────────────────

    public function assignSubscription(Request $request, int $companyId): JsonResponse
    {
        Company::findOrFail($companyId);

        $data = $request->validate([
            'planId'   => 'required|integer|exists:subscription_plans,id',
            'months'   => 'nullable|integer|min:1|max:24',
        ]);

        $months = $data['months'] ?? 1;

        // Cancel any active subscription first
        Subscription::where('company_id', $companyId)
            ->where('status', 'active')
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $subscription = Subscription::create([
            'company_id'           => $companyId,
            'plan_id'              => $data['planId'],
            'status'               => 'active',
            'current_period_start' => now(),
            'current_period_end'   => now()->addMonths($months),
            'next_billing_date'    => now()->addMonths($months),
            'auto_renew'           => true,
        ]);

        // Activate company
        Company::where('id', $companyId)->update(['status' => 'active']);

        return $this->success($this->formatSubscription($subscription->load('plan')), 'Subscription assigned.', 201);
    }

    public function cancelSubscription(int $companyId): JsonResponse
    {
        $subscription = Subscription::where('company_id', $companyId)
            ->where('status', 'active')
            ->latest()
            ->firstOrFail();

        $subscription->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return $this->success(['id' => $subscription->id, 'status' => 'cancelled'], 'Subscription cancelled.');
    }

    // ── Super Admin Management ────────────────────────────────────────────────

    public function createSuperAdmin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fullName' => 'required|string|max:255',
            'email'    => 'required|email|unique:saas_users,email',
            'password' => 'required|string|min:8',
        ]);

        $admin = SaasUser::create([
            'company_id' => null,
            'full_name'  => $data['fullName'],
            'email'      => $data['email'],
            'password'   => $data['password'],
            'role'       => 'super_admin',
            'status'     => 'active',
            'joined_date'=> now(),
        ]);

        return $this->success([
            'id'       => $admin->id,
            'fullName' => $admin->full_name,
            'email'    => $admin->email,
            'role'     => $admin->role,
        ], 'Super admin created.', 201);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function formatCompany(Company $c): array
    {
        $subscription = $c->subscriptions->first();
        return [
            'id'           => $c->id,
            'name'         => $c->name,
            'email'        => $c->email,
            'phone'        => $c->phone,
            'country'      => $c->country,
            'status'       => $c->status,
            'usersCount'   => $c->saas_users_count ?? 0,
            'createdAt'    => $c->created_at->toIso8601String(),
            'subscription' => $subscription ? $this->formatSubscription($subscription) : null,
        ];
    }

    private function formatCompanyDetail(Company $c): array
    {
        $base = $this->formatCompany($c);
        $base['users'] = $c->saasUsers->map(fn($u) => [
            'id'         => $u->id,
            'fullName'   => $u->full_name,
            'email'      => $u->email,
            'role'       => $u->role,
            'status'     => $u->status,
            'joinedDate' => $u->joined_date?->toIso8601String(),
            'lastLogin'  => $u->last_login?->toIso8601String(),
        ])->values()->all();

        return $base;
    }

    private function formatSubscription(Subscription $s): array
    {
        return [
            'id'                 => $s->id,
            'status'             => $s->status,
            'planId'             => $s->plan_id,
            'planName'           => $s->plan?->name,
            'planPrice'          => $s->plan ? $s->plan->price / 100 : null,
            'currentPeriodStart' => $s->current_period_start?->toIso8601String(),
            'currentPeriodEnd'   => $s->current_period_end?->toIso8601String(),
            'autoRenew'          => $s->auto_renew,
            'cancelledAt'        => $s->cancelled_at?->toIso8601String(),
        ];
    }

    private function formatPlan(SubscriptionPlan $p): array
    {
        $features = $p->features;
        if (is_string($features)) $features = json_decode($features, true) ?? [];

        return [
            'id'          => $p->id,
            'name'        => $p->name,
            'description' => $p->description,
            'price'       => $p->price / 100,
            'maxUsers'    => $p->max_users,
            'maxProducts' => $p->max_products,
            'maxBranches' => $p->max_branches,
            'features'    => $features,
            'isFeatured'  => $p->is_featured,
            'isActive'    => $p->is_active,
            'createdAt'   => $p->created_at->toIso8601String(),
        ];
    }
}
