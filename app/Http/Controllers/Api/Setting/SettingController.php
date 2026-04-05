<?php

namespace App\Http\Controllers\Api\Setting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\UpdateSettingRequest;
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

    public function uploadLogo(UploadImageRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $uploadedBy = (int) $request->attributes->get('auth_saas_user_id');

        // Validate file size (5MB max)
        if ($request->file('file')->getSize() > 5 * 1024 * 1024) {
            return $this->error('Logo file size must not exceed 5MB', 422);
        }

        $dto = $this->settingService->uploadLogo($companyId, $request->file('file'), $uploadedBy);

        return $this->success(
            $dto->toArray(),
            'Logo uploaded successfully',
            201
        );
    }

    public function uploadBanner(UploadImageRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $uploadedBy = (int) $request->attributes->get('auth_saas_user_id');

        // Validate file size (10MB max)
        if ($request->file('file')->getSize() > 10 * 1024 * 1024) {
            return $this->error('Banner file size must not exceed 10MB', 422);
        }

        $dto = $this->settingService->uploadBanner($companyId, $request->file('file'), $uploadedBy);

        return $this->success(
            $dto->toArray(),
            'Banner uploaded successfully',
            201
        );
    }
}
