<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateCompanyProfileRequest;
use App\Http\Requests\Company\UpdateCompanySettingsRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Company\CompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CompanyService $companyService)
    {
    }

    public function profile(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->companyService->getProfile($companyId);

        return $this->success($dto->toArray());
    }

    public function updateProfile(UpdateCompanyProfileRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->companyService->updateProfile($companyId, $request->validated());

        return $this->success($dto->toArray());
    }

    public function status(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->companyService->getStatus($companyId);

        return $this->success($dto->toArray());
    }

    public function settings(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $settings = $this->companyService->getSettings($companyId);

        return $this->success($settings);
    }

    public function upsertSettings(UpdateCompanySettingsRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $settings = $this->companyService->upsertSettings($companyId, $request->validated());

        return $this->success($settings);
    }
}
