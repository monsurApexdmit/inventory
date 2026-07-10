<?php

namespace App\Repositories\Eloquent;

use App\Models\Coupon;
use App\Repositories\Contracts\ICouponRepository;
use Illuminate\Pagination\LengthAwarePaginator;

class CouponRepository extends BaseRepository implements ICouponRepository
{
    public function __construct()
    {
        parent::__construct(new Coupon());
    }

    /**
     * Find all coupons for a company with filters
     */
    public function findByCompany(int $companyId, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->whereNull('deleted_at');

        // Apply search filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('campaign_name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // Apply status filter
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Apply type filter
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        // Paginate
        $perPage = $filters['per_page'] ?? 20;
        return $query->paginate($perPage);
    }

    /**
     * Find coupon by ID and company
     */
    public function findByIdAndCompany(int $id, int $companyId): ?Coupon
    {
        return $this->model
            ->where('company_id', $companyId)
            ->find($id);
    }

    /**
     * Find active coupon by code (public lookup, no company scope)
     */
    public function findByCode(string $code): ?Coupon
    {
        return $this->model
            ->where('code', $code)
            ->where('status', true)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Find coupon by code and company (for validation endpoint)
     */
    public function findByCodeAndCompany(string $code, int $companyId): ?Coupon
    {
        return $this->model
            ->where('code', $code)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Get usage statistics for a coupon
     */
    public function getUsageStats(int $couponId): array
    {
        $coupon = $this->model->find($couponId);
        if (!$coupon) {
            return [];
        }

        $usages = $coupon->usages()->whereNull('deleted_at')->get();

        // Count unique customers
        $uniqueCustomers = $usages->filter(fn($u) => $u->customer_id !== null)
            ->pluck('customer_id')
            ->unique()
            ->count();

        return [
            'total_uses' => $usages->count(),
            'total_discount_given' => $usages->sum('discount_applied'),
            'unique_customers' => $uniqueCustomers,
            'usages' => $usages->toArray(),
        ];
    }
}
