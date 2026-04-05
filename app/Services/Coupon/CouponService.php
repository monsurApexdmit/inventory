<?php

namespace App\Services\Coupon;

use App\DTOs\Coupon\CouponDTO;
use App\DTOs\Coupon\CouponMapper;
use App\Models\Coupon;
use App\Repositories\Contracts\ICouponRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CouponService
{
    private readonly CouponMapper $mapper;

    public function __construct(private readonly ICouponRepository $repository)
    {
        $this->mapper = new CouponMapper();
    }

    /**
     * List all coupons for a company
     */
    public function list(int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCompany($companyId, $filters);
        $data = array_map(fn ($coupon) => $this->mapper->toDTO($coupon), $paginated->items());

        return [
            'data' => $data,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    /**
     * Get a single coupon by ID
     */
    public function get(int $id, int $companyId): CouponDTO
    {
        $coupon = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$coupon) {
            throw new HttpException(404, 'Coupon not found');
        }

        return $this->mapper->toDTO($coupon);
    }

    /**
     * Get coupon by code (public lookup)
     */
    public function getByCode(string $code): CouponDTO
    {
        $coupon = $this->repository->findByCode($code);

        if (!$coupon) {
            throw new HttpException(404, 'Coupon not found or inactive');
        }

        return $this->mapper->toDTO($coupon);
    }

    /**
     * Create a new coupon
     */
    public function create(int $companyId, array $data): CouponDTO
    {
        // Validate date range
        if ($data['end_date'] <= $data['start_date']) {
            throw new HttpException(400, 'End date must be after start date');
        }

        // Check code uniqueness within company
        $exists = Coupon::where('company_id', $companyId)
            ->where('code', $data['code'])
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw new HttpException(400, 'Coupon code already exists for this company');
        }

        $data['company_id'] = $companyId;
        // Set defaults for optional boolean fields
        $data['status'] = $data['status'] ?? false;
        $data['free_shipping'] = $data['free_shipping'] ?? false;
        $data['stackable'] = $data['stackable'] ?? false;
        $data['auto_apply'] = $data['auto_apply'] ?? false;
        $data['min_order_amount'] = $data['min_order_amount'] ?? 0;

        return DB::transaction(function () use ($data) {
            $coupon = Coupon::create($data);
            return $this->mapper->toDTO($coupon->fresh());
        });
    }

    /**
     * Create coupon with image upload
     */
    public function createWithImage(int $companyId, array $data, $imageFile): CouponDTO
    {
        // Validate date range using Carbon for proper date comparison
        $startDate = \Carbon\Carbon::parse($data['start_date']);
        $endDate = \Carbon\Carbon::parse($data['end_date']);
        if ($endDate->lte($startDate)) {
            throw new HttpException(400, 'End date must be after start date');
        }

        // Check code uniqueness
        $exists = Coupon::where('company_id', $companyId)
            ->where('code', $data['code'])
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw new HttpException(400, 'Coupon code already exists for this company');
        }

        $data['company_id'] = $companyId;
        // Set defaults for optional boolean fields
        $data['status'] = $data['status'] ?? false;
        $data['free_shipping'] = $data['free_shipping'] ?? false;
        $data['stackable'] = $data['stackable'] ?? false;
        $data['auto_apply'] = $data['auto_apply'] ?? false;
        $data['min_order_amount'] = $data['min_order_amount'] ?? 0;

        return DB::transaction(function () use ($data, $imageFile) {
            // Save image
            if ($imageFile) {
                $path = $imageFile->store('coupons', 'public');
                $data['image'] = $path;
            }

            $coupon = Coupon::create($data);
            return $this->mapper->toDTO($coupon->fresh());
        });
    }

    /**
     * Update a coupon
     */
    public function update(int $id, int $companyId, array $data): CouponDTO
    {
        $coupon = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$coupon) {
            throw new HttpException(404, 'Coupon not found');
        }

        // Filter out only null values (NOT false, 0, or empty string)
        $data = array_filter($data, fn($value) => !is_null($value));

        // Validate date range if dates are provided
        if (isset($data['start_date']) && isset($data['end_date'])) {
            $startDate = Carbon::parse($data['start_date']);
            $endDate = Carbon::parse($data['end_date']);
            if ($endDate->lte($startDate)) {
                throw new HttpException(400, 'End date must be after start date');
            }
        }

        // Check code uniqueness if code is being changed
        if (isset($data['code']) && $data['code'] !== $coupon->code) {
            $exists = Coupon::where('company_id', $companyId)
                ->where('code', $data['code'])
                ->where('id', '!=', $id)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                throw new HttpException(400, 'Coupon code already exists for this company');
            }
        }

        if (!empty($data)) {
            $coupon->update($data);
        }

        return $this->mapper->toDTO($coupon->fresh());
    }

    /**
     * Update coupon with optional image upload
     */
    public function updateWithImage(int $id, int $companyId, array $data, $imageFile = null): CouponDTO
    {
        $coupon = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$coupon) {
            throw new HttpException(404, 'Coupon not found');
        }

        // Filter out only null values (NOT false, 0, or empty string)
        $data = array_filter($data, fn($value) => !is_null($value));

        // Validate date range if dates are provided
        if (isset($data['start_date']) && isset($data['end_date'])) {
            $startDate = \Carbon\Carbon::parse($data['start_date']);
            $endDate = \Carbon\Carbon::parse($data['end_date']);
            if ($endDate->lte($startDate)) {
                throw new HttpException(400, 'End date must be after start date');
            }
        }

        // Check code uniqueness if code is being changed
        if (isset($data['code']) && $data['code'] !== $coupon->code) {
            $exists = Coupon::where('company_id', $companyId)
                ->where('code', $data['code'])
                ->where('id', '!=', $id)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                throw new HttpException(400, 'Coupon code already exists for this company');
            }
        }

        return DB::transaction(function () use ($coupon, $data, $imageFile) {
            // Handle image update
            if ($imageFile) {
                // Delete old image if exists
                if ($coupon->image) {
                    Storage::disk('public')->delete($coupon->image);
                }

                // Save new image
                $path = $imageFile->store('coupons', 'public');
                $data['image'] = $path;
            }

            $coupon->update($data);
            return $this->mapper->toDTO($coupon->fresh());
        });
    }

    /**
     * Delete a coupon
     */
    public function delete(int $id, int $companyId): void
    {
        $coupon = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$coupon) {
            throw new HttpException(404, 'Coupon not found');
        }

        DB::transaction(function () use ($coupon) {
            // Delete image file if exists
            if ($coupon->image) {
                Storage::disk('public')->delete($coupon->image);
            }

            // Soft delete
            $coupon->delete();
        });
    }

    /**
     * Validate coupon at checkout
     * Returns discount amount if valid, throws HttpException 422 if invalid
     */
    public function validate(int $companyId, array $data): array
    {
        $code = $data['code'];
        $customerId = $data['customerId'] ?? null;
        $orderAmount = $data['orderAmount'];
        $cartItems = $data['cartItems'] ?? [];

        // 1. Find coupon
        $coupon = $this->repository->findByCodeAndCompany($code, $companyId);
        if (!$coupon) {
            throw new HttpException(422, json_encode([
                'valid' => false,
                'error_code' => 'COUPON_NOT_FOUND',
                'message' => 'This coupon does not exist',
            ]));
        }

        // 2. Check if active
        if (!$coupon->status) {
            throw new HttpException(422, json_encode([
                'valid' => false,
                'error_code' => 'COUPON_INACTIVE',
                'message' => 'This coupon is not active',
            ]));
        }

        // 3. Check date range
        $now = Carbon::now();
        if ($now < $coupon->start_date) {
            throw new HttpException(422, json_encode([
                'valid' => false,
                'error_code' => 'COUPON_NOT_STARTED',
                'message' => 'This coupon is not yet active',
            ]));
        }

        if ($now > $coupon->end_date) {
            throw new HttpException(422, json_encode([
                'valid' => false,
                'error_code' => 'COUPON_EXPIRED',
                'message' => 'This coupon has expired',
            ]));
        }

        // 4. Check minimum order amount
        if ($orderAmount < $coupon->min_order_amount) {
            throw new HttpException(422, json_encode([
                'valid' => false,
                'error_code' => 'MIN_ORDER_AMOUNT_NOT_MET',
                'message' => 'Order does not meet minimum amount requirement',
            ]));
        }

        // 5. Check total usage limit
        if ($coupon->usage_limit !== null && $coupon->times_used >= $coupon->usage_limit) {
            throw new HttpException(422, json_encode([
                'valid' => false,
                'error_code' => 'USAGE_LIMIT_EXCEEDED',
                'message' => 'This coupon has reached its usage limit',
            ]));
        }

        // 6. Check per-user usage limit
        if ($coupon->usage_limit_per_user !== null && $customerId !== null) {
            $userUsageCount = $coupon->usages()
                ->where('customer_id', $customerId)
                ->whereNull('deleted_at')
                ->count();

            if ($userUsageCount >= $coupon->usage_limit_per_user) {
                throw new HttpException(422, json_encode([
                    'valid' => false,
                    'error_code' => 'PER_USER_LIMIT_EXCEEDED',
                    'message' => 'You have reached the usage limit for this coupon',
                ]));
            }
        }

        // 7. Check product/category applicability
        if (!$this->isApplicableToCart($coupon, $cartItems)) {
            throw new HttpException(422, json_encode([
                'valid' => false,
                'error_code' => 'NOT_APPLICABLE',
                'message' => 'This coupon is not applicable to your cart',
            ]));
        }

        // 8. Calculate discount
        $discountAmount = $this->calculateDiscount($coupon, $orderAmount);

        return [
            'valid' => true,
            'discountAmount' => $discountAmount,
        ];
    }

    /**
     * Check if coupon applies to cart items
     */
    private function isApplicableToCart(Coupon $coupon, array $cartItems): bool
    {
        // If no restrictions, applies to all
        if (empty($coupon->applicable_to_products) && empty($coupon->applicable_to_categories)) {
            return true;
        }

        // If cart is empty, not applicable
        if (empty($cartItems)) {
            return false;
        }

        $applicableProducts = $coupon->applicable_to_products
            ? array_map('intval', explode(',', $coupon->applicable_to_products))
            : [];
        $applicableCategories = $coupon->applicable_to_categories
            ? array_map('intval', explode(',', $coupon->applicable_to_categories))
            : [];

        foreach ($cartItems as $item) {
            $productId = $item['product_id'] ?? null;
            $categoryId = $item['category_id'] ?? null;

            // Check if product is applicable
            if (!empty($applicableProducts) && $productId && in_array($productId, $applicableProducts)) {
                return true;
            }

            // Check if category is applicable
            if (!empty($applicableCategories) && $categoryId && in_array($categoryId, $applicableCategories)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate discount amount based on coupon type
     */
    private function calculateDiscount(Coupon $coupon, float $orderAmount): float
    {
        if ($coupon->type === 'percentage') {
            $discount = $orderAmount * ($coupon->discount / 100);

            // Apply max_discount cap if set
            if ($coupon->max_discount !== null) {
                $discount = min($discount, $coupon->max_discount);
            }

            return round($discount, 2);
        }

        if ($coupon->type === 'fixed') {
            return round((float) $coupon->discount, 2);
        }

        // free_shipping type
        return 0;
    }

    /**
     * Get usage statistics for a coupon
     */
    public function getUsageStats(int $id, int $companyId): array
    {
        $coupon = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$coupon) {
            throw new HttpException(404, 'Coupon not found');
        }

        return $this->repository->getUsageStats($id);
    }
}
