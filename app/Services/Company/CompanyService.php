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
                'currencySymbolPosition' => 'before',
                'currencyDecimalSeparator' => '.',
                'currencyThousandsSeparator' => ',',
                'currencyDecimalPlaces' => 2,
                'weightUnit' => 'kg',
                'dimensionUnit' => 'cm',
                'dateFormat' => 'MM/DD/YYYY',
                'timeFormat' => '12h',
            ];
        }

        $result = [
            'companyId' => $settings->company_id,
            'companyName' => $settings->company_name ?? '',
            'taxId' => $settings->tax_id ?? '',
            'taxIdType' => $settings->tax_id_type ?? '',
            'taxRate' => (float) ($settings->tax_rate ?? 0),
            'currency' => $settings->currency ?? 'USD',
            'timezone' => $settings->timezone ?? 'UTC',
            'language' => $settings->language ?? 'en',
        ];

        // Add international settings if columns exist (for backward compatibility with older migrations)
        // Use getAttribute() which safely returns null if attribute doesn't exist
        $result['currencySymbolPosition'] = $settings->getAttribute('currency_symbol_position') ?? 'before';
        $result['currencyDecimalSeparator'] = $settings->getAttribute('currency_decimal_separator') ?? '.';
        $result['currencyThousandsSeparator'] = $settings->getAttribute('currency_thousands_separator') ?? ',';
        $result['currencyDecimalPlaces'] = (int) ($settings->getAttribute('currency_decimal_places') ?? 2);
        $result['weightUnit'] = $settings->getAttribute('weight_unit') ?? 'kg';
        $result['dimensionUnit'] = $settings->getAttribute('dimension_unit') ?? 'cm';
        $result['dateFormat'] = $settings->getAttribute('date_format') ?? 'MM/DD/YYYY';
        $result['timeFormat'] = $settings->getAttribute('time_format') ?? '12h';

        return $result;
    }

    public function upsertSettings(int $companyId, array $data): array
    {
        // Get existing settings to check which columns exist in the database
        $existing = $this->companySettingsRepository->findByCompanyId($companyId);

        // Check which international settings columns exist in the database
        $hasInternationalColumns = false;
        if ($existing) {
            $existingAttributes = $existing->getAttributes();
            $hasInternationalColumns = isset($existingAttributes['currency_symbol_position']);
        }

        // Prepare update data - convert camelCase to snake_case
        $updateData = [];

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

        // International/Regional Settings - only add if columns exist in database
        if ($hasInternationalColumns) {
            if (isset($data['currencySymbolPosition'])) {
                $updateData['currency_symbol_position'] = $data['currencySymbolPosition'];
            } elseif ($existing) {
                $val = $existing->getAttribute('currency_symbol_position');
                if ($val !== null) {
                    $updateData['currency_symbol_position'] = $val;
                }
            }

            if (isset($data['currencyDecimalSeparator'])) {
                $updateData['currency_decimal_separator'] = $data['currencyDecimalSeparator'];
            } elseif ($existing) {
                $val = $existing->getAttribute('currency_decimal_separator');
                if ($val !== null) {
                    $updateData['currency_decimal_separator'] = $val;
                }
            }

            if (isset($data['currencyThousandsSeparator'])) {
                $updateData['currency_thousands_separator'] = $data['currencyThousandsSeparator'];
            } elseif ($existing) {
                $val = $existing->getAttribute('currency_thousands_separator');
                if ($val !== null) {
                    $updateData['currency_thousands_separator'] = $val;
                }
            }

            if (isset($data['currencyDecimalPlaces'])) {
                $updateData['currency_decimal_places'] = $data['currencyDecimalPlaces'];
            } elseif ($existing) {
                $val = $existing->getAttribute('currency_decimal_places');
                if ($val !== null) {
                    $updateData['currency_decimal_places'] = $val;
                }
            }

            if (isset($data['weightUnit'])) {
                $updateData['weight_unit'] = $data['weightUnit'];
            } elseif ($existing) {
                $val = $existing->getAttribute('weight_unit');
                if ($val !== null) {
                    $updateData['weight_unit'] = $val;
                }
            }

            if (isset($data['dimensionUnit'])) {
                $updateData['dimension_unit'] = $data['dimensionUnit'];
            } elseif ($existing) {
                $val = $existing->getAttribute('dimension_unit');
                if ($val !== null) {
                    $updateData['dimension_unit'] = $val;
                }
            }

            if (isset($data['dateFormat'])) {
                $updateData['date_format'] = $data['dateFormat'];
            } elseif ($existing) {
                $val = $existing->getAttribute('date_format');
                if ($val !== null) {
                    $updateData['date_format'] = $val;
                }
            }

            if (isset($data['timeFormat'])) {
                $updateData['time_format'] = $data['timeFormat'];
            } elseif ($existing) {
                $val = $existing->getAttribute('time_format');
                if ($val !== null) {
                    $updateData['time_format'] = $val;
                }
            }
        }

        $this->companySettingsRepository->upsert($companyId, $updateData);

        return $this->getSettings($companyId);
    }
}
