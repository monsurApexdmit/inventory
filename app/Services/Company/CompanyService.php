<?php

namespace App\Services\Company;

use App\DTOs\Company\CompanyDTO;
use App\DTOs\Company\CompanyMapper;
use App\Repositories\Contracts\ICompanyRepository;
use App\Repositories\Contracts\ICompanySettingsRepository;
use App\Repositories\Contracts\ISubscriptionRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CompanyService
{
    private readonly CompanyMapper $mapper;

    public function __construct(
        private readonly ICompanyRepository $companyRepository,
        private readonly ICompanySettingsRepository $companySettingsRepository,
        private readonly ISubscriptionRepository $subscriptionRepository,
    ) {
        $this->mapper = new CompanyMapper();
    }

    public function getProfile(int $companyId): CompanyDTO
    {
        $company = $this->companyRepository->findById($companyId);

        if (!$company) {
            throw new HttpException(404, 'Company not found');
        }

        return $this->mapper->toDTO($company);
    }

    public function updateProfile(int $companyId, array $data): CompanyDTO
    {
        $company = $this->companyRepository->findById($companyId);

        if (!$company) {
            throw new HttpException(404, 'Company not found');
        }

        // Filter out empty strings and nulls (Laravel's nullable validation converts empty strings to null)
        $updateData = array_filter($data, fn ($value) => $value !== '' && $value !== null);

        $this->companyRepository->update($companyId, $updateData);

        return $this->getProfile($companyId);
    }

    public function getStatus(int $companyId): array
    {
        $company = $this->companyRepository->findById($companyId);

        if (!$company) {
            throw new HttpException(404, 'Company not found');
        }

        $subscription = $this->subscriptionRepository->findByCompanyId($companyId);
        $activeUsers = $company->saasUsers()->where('status', 'active')->count();

        return [
            'id' => $company->id,
            'name' => $company->name,
            'plan' => $subscription?->plan?->name ?? 'Trial',
            'maxUsers' => $subscription?->plan?->max_users ?? 10,
            'activeUsers' => $activeUsers,
            'status' => $subscription?->status ?? 'active',
            'currentPeriodEnd' => $subscription?->current_period_end,
        ];
    }

    public function getSettings(int $companyId): array
    {
        $settings = $this->companySettingsRepository->findByCompanyId($companyId);

        if (!$settings) {
            // Return zero-value DTO if no record
            return [
                'companyId' => $companyId,
                'companyName' => '',
                'taxId' => '',
                'taxIdType' => '',
                'taxRate' => 0.0,
                'currency' => 'USD',
                'timezone' => 'UTC',
                'language' => 'en',
            ];
        }

        return [
            'companyId' => $settings->company_id,
            'companyName' => $settings->company_name,
            'taxId' => $settings->tax_id,
            'taxIdType' => $settings->tax_id_type,
            'taxRate' => (float) $settings->tax_rate,
            'currency' => $settings->currency,
            'timezone' => $settings->timezone,
            'language' => $settings->language,
        ];
    }

    public function upsertSettings(int $companyId, array $data): array
    {
        // Get existing settings to merge with new data
        $existing = $this->companySettingsRepository->findByCompanyId($companyId);

        // Prepare update data - convert camelCase to snake_case
        $updateData = [];
        if (isset($data['companyName'])) {
            $updateData['company_name'] = $data['companyName'];
        } elseif ($existing) {
            $updateData['company_name'] = $existing->company_name;
        }

        if (isset($data['taxId'])) {
            $updateData['tax_id'] = $data['taxId'];
        } elseif ($existing) {
            $updateData['tax_id'] = $existing->tax_id;
        }

        if (isset($data['taxIdType'])) {
            $updateData['tax_id_type'] = $data['taxIdType'];
        } elseif ($existing) {
            $updateData['tax_id_type'] = $existing->tax_id_type;
        }

        if (isset($data['taxRate'])) {
            $updateData['tax_rate'] = $data['taxRate'];
        } elseif ($existing) {
            $updateData['tax_rate'] = $existing->tax_rate;
        }

        if (isset($data['currency'])) {
            $updateData['currency'] = $data['currency'];
        } elseif ($existing) {
            $updateData['currency'] = $existing->currency;
        } else {
            $updateData['currency'] = 'USD';
        }

        if (isset($data['timezone'])) {
            $updateData['timezone'] = $data['timezone'];
        } elseif ($existing) {
            $updateData['timezone'] = $existing->timezone;
        } else {
            $updateData['timezone'] = 'UTC';
        }

        if (isset($data['language'])) {
            $updateData['language'] = $data['language'];
        } elseif ($existing) {
            $updateData['language'] = $existing->language;
        } else {
            $updateData['language'] = 'en';
        }

        $this->companySettingsRepository->upsert($companyId, $updateData);

        return $this->getSettings($companyId);
    }
}
