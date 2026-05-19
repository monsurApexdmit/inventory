<?php

namespace App\Http\Controllers\Api\Setting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\UpdateSettingRequest;
use App\Http\Requests\Setting\UpdateRegionalSettingRequest;
use App\Http\Requests\Setting\UploadImageRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Setting\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SettingService $settingService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->settingService->getAll($companyId));
    }

    public function updateSection(Request $request, string $section): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->all();

        return $this->success($this->settingService->updateSection($companyId, $section, $data));
    }

    // Specific section endpoints
    public function updateGeneral(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->settingService->updateSection($companyId, 'general', $request->all());
        return $this->success($result, 'General settings updated successfully');
    }

    public function updateTax(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->settingService->updateSection($companyId, 'tax', $request->all());
        return $this->success($result, 'Tax settings updated successfully');
    }

    public function updateShipping(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->settingService->updateSection($companyId, 'shipping', $request->all());
        return $this->success($result, 'Shipping settings updated successfully');
    }

    public function updatePayment(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->settingService->updateSection($companyId, 'payment', $request->all());
        return $this->success($result, 'Payment settings updated successfully');
    }

    public function updateBusiness(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->settingService->updateSection($companyId, 'business', $request->all());
        return $this->success($result, 'Business settings updated successfully');
    }

    public function updateRegional(UpdateRegionalSettingRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->settingService->updateSection($companyId, 'regional', $request->validated());
        return $this->success($result, 'Regional settings updated successfully');
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->settingService->updateSection($companyId, 'notifications', $request->all());
        return $this->success($result, 'Notification settings updated successfully');
    }

    public function getStoreHours(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->settingService->getStoreHours($companyId), 'Store hours retrieved successfully');
    }

    public function updateStoreHours(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->settingService->updateSection($companyId, 'store-hours', $request->all());
        return $this->success($result, 'Store hours updated successfully');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->error('Unauthorized', 401);
        }

        $request->validate([
            'currentPassword' => 'required|string',
            'newPassword' => 'required|string|min:8',
            'confirmPassword' => 'required|string|same:newPassword',
        ]);

        // Verify current password
        if (!\Hash::check($request->input('currentPassword'), $user->password)) {
            return $this->error('Current password is incorrect', 422);
        }

        // Update password
        $user->update([
            'password' => \Hash::make($request->input('newPassword')),
        ]);

        return $this->success(['message' => 'Password changed successfully']);
    }

    public function uploadLogo(UploadImageRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $uploadedBy = (int) $request->attributes->get('auth_user_id');

        // Validate file size (5MB max)
        if ($request->file('file')->getSize() > 5 * 1024 * 1024) {
            return $this->error('Logo file size must not exceed 5MB', 422);
        }

        $payload = $this->settingService->uploadLogo($companyId, $request->file('file'), $uploadedBy);

        return $this->success(
            $payload,
            'Logo uploaded successfully',
            201
        );
    }

    public function uploadBanner(UploadImageRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $uploadedBy = (int) $request->attributes->get('auth_user_id');

        // Validate file size (10MB max)
        if ($request->file('file')->getSize() > 10 * 1024 * 1024) {
            return $this->error('Banner file size must not exceed 10MB', 422);
        }

        $payload = $this->settingService->uploadBanner($companyId, $request->file('file'), $uploadedBy);

        return $this->success(
            $payload,
            'Banner uploaded successfully',
            201
        );
    }

    public function uploadStorefrontImage(UploadImageRequest $request): JsonResponse
    {
        if ($request->file('file')->getSize() > 10 * 1024 * 1024) {
            return $this->error('Image file size must not exceed 10MB', 422);
        }

        $payload = $this->settingService->uploadStorefrontImage($request->file('file'));

        return $this->success(
            $payload,
            'Storefront image uploaded successfully',
            201
        );
    }
}
