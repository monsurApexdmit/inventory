<?php

namespace App\Http\Controllers\Api\V1\Coupon;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coupon\CreateCouponRequest;
use App\Http\Requests\Coupon\UpdateCouponRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Coupon\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CouponController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CouponService $couponService)
    {
    }

    /**
     * GET /coupons/
     * List all coupons for the company
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->all();

        $result = $this->couponService->list($companyId, $filters);

        return $this->success($result['data'], 'Coupons retrieved successfully', 200, [
            'total' => $result['total'],
            'per_page' => $result['per_page'],
            'current_page' => $result['current_page'],
            'last_page' => $result['last_page'],
        ]);
    }

    /**
     * GET /coupons/{id}
     * Get a single coupon
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        try {
            $dto = $this->couponService->get($id, $companyId);
            return $this->success($dto->toArray(), 'Coupon fetched successfully');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    /**
     * GET /coupons/code/{code}
     * Public coupon lookup by code (no authentication)
     */
    public function getByCode(string $code): JsonResponse
    {
        try {
            $dto = $this->couponService->getByCode($code);
            return $this->success($dto->toArray(), 'Coupon found');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    /**
     * POST /coupons/
     * Create a coupon
     */
    public function store(CreateCouponRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        try {
            $dto = $this->couponService->create($companyId, $request->validated());
            return $this->success($dto->toArray(), 'Coupon created successfully', 201);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    /**
     * POST /coupons/with-image
     * Create a coupon with image upload
     */
    public function storeWithImage(CreateCouponRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $imageFile = $request->file('image');

        try {
            $dto = $this->couponService->createWithImage($companyId, $request->validated(), $imageFile);
            return $this->success($dto->toArray(), 'Coupon created successfully', 201);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    /**
     * PUT /coupons/{id}
     * Update a coupon
     */
    public function update(UpdateCouponRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        try {
            $validated = $request->validated();
            $dto = $this->couponService->update($id, $companyId, $validated);
            return $this->success($dto->toArray(), 'Coupon updated successfully');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    /**
     * PUT /coupons/{id}/with-image
     * Update a coupon with optional image upload
     */
    public function updateWithImage(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        try {
            // Get all request data (including form fields)
            $allData = $request->all();
            $imageFile = $request->file('image');

            // Filter out null values and the image file itself
            $data = array_filter($allData, fn($value) => !is_null($value) && !($value instanceof \Illuminate\Http\UploadedFile));

            file_put_contents('/tmp/coupon_debug.txt', json_encode([
                'data' => $data,
                'has_image' => !is_null($imageFile),
                'content_type' => $request->header('Content-Type'),
            ]));

            // If image is provided, update with image
            if ($imageFile !== null) {
                $dto = $this->couponService->updateWithImage($id, $companyId, $data, $imageFile);
                return $this->success($dto->toArray(), 'Coupon and image updated successfully');
            }

            // No image provided, proceed with normal update
            $dto = $this->couponService->updateWithImage($id, $companyId, $data, null);
            return $this->success($dto->toArray(), 'Coupon updated successfully');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    /**
     * DELETE /coupons/{id}
     * Delete a coupon
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        try {
            $this->couponService->delete($id, $companyId);
            return $this->success([], 'Coupon deleted successfully');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    /**
     * POST /coupons/validate
     * Validate coupon at checkout
     */
    public function validateCoupon(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        try {
            $result = $this->couponService->validate($companyId, $request->all());
            return $this->success($result, 'Coupon is valid');
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 422) {
                // Parse the JSON error message
                $body = json_decode($e->getMessage(), true);
                return response()->json($body, 422);
            }

            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    /**
     * GET /coupons/{id}/usage-stats
     * Get usage statistics for a coupon
     */
    public function getUsageStats(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        try {
            $stats = $this->couponService->getUsageStats($id, $companyId);
            return $this->success($stats, 'Usage statistics retrieved successfully');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }
}
