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
        $this->assertRules($companyId, $data, null);

        $data['company_id'] = $companyId;
        // Set defaults for optional boolean fields
        $data['status'] = $data['status'] ?? false;
        $data['free_shipping'] = $data['free_shipping'] ?? false;
        $data['stackable'] = $data['stackable'] ?? false;
        $data['auto_apply'] = $data['auto_apply'] ?? false;
        $data['min_order_amount'] = $data['min_order_amount'] ?? 0;
        $data['channel_pos'] = $data['channel_pos'] ?? true;
        $data['channel_online'] = $data['channel_online'] ?? true;
        $data['once_per_customer'] = $data['once_per_customer'] ?? false;
        unset($data['applies_to']);

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
        $this->assertRules($companyId, $data, null);

        $data['company_id'] = $companyId;
        // Set defaults for optional boolean fields
        $data['status'] = $data['status'] ?? false;
        $data['free_shipping'] = $data['free_shipping'] ?? false;
        $data['stackable'] = $data['stackable'] ?? false;
        $data['auto_apply'] = $data['auto_apply'] ?? false;
        $data['min_order_amount'] = $data['min_order_amount'] ?? 0;
        $data['channel_pos'] = $data['channel_pos'] ?? true;
        $data['channel_online'] = $data['channel_online'] ?? true;
        $data['once_per_customer'] = $data['once_per_customer'] ?? false;
        unset($data['applies_to']);

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

        // Filter out only null values (NOT false, 0, or empty string).
        // When appliesTo was sent, explicit nulls for the scope columns mean "clear".
        $clearScope = [];
        if (array_key_exists('applies_to', $data)) {
            foreach (['applicable_to_categories', 'applicable_to_products'] as $col) {
                if (array_key_exists($col, $data) && $data[$col] === null) {
                    $clearScope[$col] = null;
                }
            }
        }
        // Optional limits may be cleared explicitly (end date => "No end", limits => unlimited).
        $clearNullable = [];
        foreach (['end_date', 'usage_limit', 'usage_limit_per_user', 'max_discount'] as $col) {
            if (array_key_exists($col, $data) && $data[$col] === null) {
                $clearNullable[$col] = null;
            }
        }
        $data = array_filter($data, fn($value) => !is_null($value)) + $clearScope + $clearNullable;
        unset($data['applies_to']);

        $this->assertRules($companyId, $data, $coupon);

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

        $this->assertRules($companyId, $data, $coupon);

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
     * Business rules shared by create and update. Throws HTTP 422 with a readable message.
     * $existing is the coupon being edited (null on create); only keys present in $data are checked on update.
     */
    private function assertRules(int $companyId, array $data, ?Coupon $existing): void
    {
        $has = fn (string $k) => array_key_exists($k, $data);
        $fail = fn (string $m) => throw new HttpException(422, $m);

        // Code: allowed characters + unique per company (case-insensitive via collation)
        if ($has('code') && $data['code'] !== null) {
            if (!preg_match('/^[A-Za-z0-9_-]{3,50}$/', (string) $data['code'])) {
                $fail('Use letters, numbers, dashes and underscores only (3-50 characters)');
            }
            $dup = Coupon::where('company_id', $companyId)
                ->where('code', $data['code'])
                ->whereNull('deleted_at')
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->exists();
            if ($dup) {
                $fail('A coupon with this code already exists');
            }
        }

        // Dates: end date is optional; the end date's whole day counts, so only a strictly earlier day is wrong.
        $start = $has('start_date') ? $data['start_date'] : $existing?->start_date;
        $end = $has('end_date') ? $data['end_date'] : $existing?->end_date;
        if ($start && $end && Carbon::parse($end)->startOfDay()->lt(Carbon::parse($start)->startOfDay())) {
            $fail('End date cannot be before the start date');
        }

        // Percentage must be <= 100
        $type = $has('type') && $data['type'] !== null ? $data['type'] : $existing?->type;
        $discount = $has('discount') && $data['discount'] !== null ? (float) $data['discount'] : $existing?->discount;
        if ($type === 'percentage' && $discount !== null && $discount > 100) {
            $fail('A percentage discount cannot be more than 100%');
        }

        // Whole numbers for use counts
        foreach (['usage_limit' => 'Total uses', 'usage_limit_per_user' => 'Uses per customer'] as $k => $label) {
            if ($has($k) && $data[$k] !== null && (floor((float) $data[$k]) != (float) $data[$k] || (float) $data[$k] < 1)) {
                $fail("{$label} must be a whole number of at least 1");
            }
        }

        // Total uses cannot be below what is already redeemed
        if ($existing && $has('usage_limit') && $data['usage_limit'] !== null
            && (int) $data['usage_limit'] < (int) $existing->times_used) {
            $fail("Total uses cannot be less than the {$existing->times_used} already redeemed");
        }
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
        $customerId = $data['customerId'] ?? $data['customer_id'] ?? null;
        $orderAmount = (float) ($data['orderAmount'] ?? $data['order_amount'] ?? $data['subtotal'] ?? 0);
        $channel = isset($data['channel']) ? strtolower((string) $data['channel']) : null;
        // Items are optional; scope (categories/products) is only enforced when they are sent.
        $itemsSent = array_key_exists('items', $data) || array_key_exists('cartItems', $data);
        $cartItems = $data['items'] ?? $data['cartItems'] ?? [];
        if (!is_array($cartItems)) {
            $cartItems = [];
        }

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

        // 2b. Check sales channel
        if ($channel !== null && in_array($channel, ['pos', 'online'], true)) {
            $enabled = $channel === 'pos' ? $coupon->channel_pos : $coupon->channel_online;
            if (!$enabled) {
                throw new HttpException(422, json_encode([
                    'valid' => false,
                    'error_code' => 'CHANNEL_NOT_ENABLED',
                    'message' => "This coupon is not valid for {$channel} sales",
                ]));
            }
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

        $endsAt = $coupon->endsAt();
        if ($endsAt && $now > $endsAt) {
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

        // 6. Check per-user usage limit (needs to know who the customer is)
        $perUserLimit = $coupon->once_per_customer ? 1 : $coupon->usage_limit_per_user;
        if ($perUserLimit !== null) {
            $customerEmail = isset($data['customerEmail']) ? strtolower(trim((string) $data['customerEmail'])) : '';
            if ($customerId !== null && $customerId !== '') {
                $userUsageCount = $coupon->usages()
                    ->where('customer_id', $customerId)
                    ->whereNull('deleted_at')
                    ->count();
            } elseif ($customerEmail !== '') {
                // Guest checkout: match earlier uses of this coupon by the order email
                $userUsageCount = DB::table('coupon_usages')
                    ->join('sells', 'sells.id', '=', 'coupon_usages.sell_id')
                    ->where('coupon_usages.coupon_id', $coupon->id)
                    ->whereNull('coupon_usages.deleted_at')
                    ->whereRaw('LOWER(sells.shipping_email) = ?', [$customerEmail])
                    ->count();
            } else {
                throw new HttpException(422, json_encode([
                    'valid' => false,
                    'error_code' => 'CUSTOMER_REQUIRED',
                    'message' => $channel === 'online'
                        ? 'Sign in or enter your email to use this coupon'
                        : 'Select a customer to use this coupon',
                ]));
            }

            if ($userUsageCount >= $perUserLimit) {
                throw new HttpException(422, json_encode([
                    'valid' => false,
                    'error_code' => 'PER_USER_LIMIT_EXCEEDED',
                    'message' => 'You have reached the usage limit for this coupon',
                ]));
            }
        }

        // 7. Check product/category applicability
        if ($itemsSent && !$this->isApplicableToCart($coupon, $cartItems, $companyId)) {
            throw new HttpException(422, json_encode([
                'valid' => false,
                'error_code' => 'NOT_APPLICABLE',
                'message' => 'This coupon is not applicable to your cart',
            ]));
        }

        // 8. Calculate discount; a scoped coupon only discounts the eligible lines (when line totals are sent)
        $discountBase = $itemsSent ? $this->eligibleSubtotal($coupon, $cartItems, $companyId, $orderAmount) : $orderAmount;
        $discountAmount = min($this->calculateDiscount($coupon, $discountBase), max(0, $orderAmount));

        return [
            'valid' => true,
            'discountAmount' => $discountAmount,
            'discount' => $discountAmount,
            'freeShipping' => (bool) $coupon->free_shipping || $coupon->type === 'free_shipping',
            'coupon' => $this->mapper->toDTO($coupon)->toArray(),
        ];
    }

    /**
     * Check if coupon applies to cart items
     */
    private function isApplicableToCart(Coupon $coupon, array $cartItems, int $companyId): bool
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

        // Resolve missing category ids from the products table.
        $needLookup = [];
        foreach ($cartItems as $item) {
            $pid = $item['product_id'] ?? $item['productId'] ?? null;
            if ($pid && empty($item['category_id'] ?? $item['categoryId'] ?? null)) {
                $needLookup[] = (int) $pid;
            }
        }
        $categoryByProduct = $needLookup
            ? \App\Models\Product::where('company_id', $companyId)->whereIn('id', $needLookup)->pluck('category_id', 'id')->all()
            : [];

        foreach ($cartItems as $item) {
            $productId = isset($item['product_id']) ? (int) $item['product_id'] : (isset($item['productId']) ? (int) $item['productId'] : null);
            $categoryId = $item['category_id'] ?? $item['categoryId'] ?? ($productId ? ($categoryByProduct[$productId] ?? null) : null);
            $categoryId = $categoryId !== null ? (int) $categoryId : null;

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
     * Subtotal the discount applies to: eligible lines for a scoped coupon, else the whole order.
     * Falls back to the order amount when the lines carry no totals.
     */
    private function eligibleSubtotal(Coupon $coupon, array $cartItems, int $companyId, float $orderAmount): float
    {
        if (empty($coupon->applicable_to_products) && empty($coupon->applicable_to_categories)) {
            return $orderAmount;
        }
        $products = $coupon->applicable_to_products ? array_map('intval', explode(',', $coupon->applicable_to_products)) : [];
        $categories = $coupon->applicable_to_categories ? array_map('intval', explode(',', $coupon->applicable_to_categories)) : [];

        $ids = [];
        foreach ($cartItems as $item) {
            $pid = $item['product_id'] ?? $item['productId'] ?? null;
            if ($pid) {
                $ids[] = (int) $pid;
            }
        }
        $cats = $ids ? \App\Models\Product::where('company_id', $companyId)->whereIn('id', $ids)->pluck('category_id', 'id')->all() : [];

        $sum = 0.0;
        $hasTotals = false;
        foreach ($cartItems as $item) {
            $line = $item['totalPrice'] ?? $item['total_price'] ?? null;
            if ($line === null && isset($item['unitPrice'], $item['quantity'])) {
                $line = (float) $item['unitPrice'] * (float) $item['quantity'];
            }
            if ($line === null) {
                continue;
            }
            $hasTotals = true;
            $pid = (int) ($item['product_id'] ?? $item['productId'] ?? 0);
            $cid = $item['category_id'] ?? $item['categoryId'] ?? ($pid ? ($cats[$pid] ?? null) : null);
            $cid = $cid !== null ? (int) $cid : null;
            if (($pid && in_array($pid, $products, true)) || ($cid && in_array($cid, $categories, true))) {
                $sum += (float) $line;
            }
        }

        return $hasTotals ? $sum : $orderAmount;
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
