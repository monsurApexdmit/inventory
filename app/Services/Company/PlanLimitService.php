<?php

namespace App\Services\Company;

use App\Models\Product;
use App\Models\SaasUser;
use App\Repositories\Contracts\ILocationRepository;
use App\Repositories\Contracts\ISubscriptionRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlanLimitService
{
    // Defaults for trial / no-plan companies
    private const TRIAL_MAX_USERS    = 2;
    private const TRIAL_MAX_BRANCHES = 1;
    private const TRIAL_MAX_PRODUCTS = 50;

    public function __construct(
        private readonly ISubscriptionRepository $subscriptionRepository,
        private readonly ILocationRepository     $locationRepository,
    ) {}

    public function getLimits(int $companyId): array
    {
        $subscription = $this->subscriptionRepository->findByCompanyId($companyId);
        $plan         = $subscription?->plan;

        $maxUsers    = $plan?->max_users    ?? self::TRIAL_MAX_USERS;
        $maxBranches = $plan?->max_branches ?? self::TRIAL_MAX_BRANCHES;
        $maxProducts = $plan?->max_products ?? self::TRIAL_MAX_PRODUCTS;

        $currentUsers    = SaasUser::where('company_id', $companyId)->where('status', 'active')->count();
        $currentBranches = $this->locationRepository->countByCompany($companyId);
        $currentProducts = Product::where('company_id', $companyId)->count();

        return [
            'plan'            => $plan ? [
                'id'    => $plan->id,
                'name'  => $plan->name,
                'price' => $plan->price,
            ] : null,
            'maxUsers'        => $maxUsers,
            'maxBranches'     => $maxBranches,
            'maxProducts'     => $maxProducts,
            'currentUsers'    => $currentUsers,
            'currentBranches' => $currentBranches,
            'currentProducts' => $currentProducts,
            'canAddUser'      => $currentUsers < $maxUsers,
            'canAddBranch'    => $currentBranches < $maxBranches,
            'canAddProduct'   => $currentProducts < $maxProducts,
        ];
    }

    public function enforceUserLimit(int $companyId): void
    {
        $limits = $this->getLimits($companyId);
        if (!$limits['canAddUser']) {
            throw new HttpException(422, "User limit reached ({$limits['currentUsers']}/{$limits['maxUsers']}). Upgrade your plan to add more team members.");
        }
    }

    public function enforceBranchLimit(int $companyId): void
    {
        $limits = $this->getLimits($companyId);
        if (!$limits['canAddBranch']) {
            throw new HttpException(422, "Branch limit reached ({$limits['currentBranches']}/{$limits['maxBranches']}). Upgrade your plan to add more branches.");
        }
    }
}
