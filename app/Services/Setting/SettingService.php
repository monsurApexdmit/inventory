<?php

namespace App\Services\Setting;

use App\Repositories\Contracts\ISettingRepository;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SettingService
{
    private const SECTION_COLUMN_MAP = [
        'general' => 'general_settings',
        'tax' => 'tax_settings',
        'shipping' => 'shipping_settings',
        'payment' => 'payment_settings',
        'business' => 'business_settings',
        'regional' => 'regional_settings',
        'notifications' => 'notification_settings',
        'store-hours' => 'store_hours',
    ];

    private const DEFAULT_SETTINGS = [
        'general_settings' => [],
        'tax_settings' => [],
        'shipping_settings' => [],
        'payment_settings' => [],
        'business_settings' => [],
        'regional_settings' => [],
        'notification_settings' => [],
        'store_hours' => [],
    ];

    public function __construct(
        private readonly ISettingRepository $settingRepository,
    ) {
    }

    public function getAll(int $companyId): array
    {
        $setting = $this->settingRepository->findByCompany($companyId);

        if (!$setting) {
            // Create defaults on first access
            $setting = $this->settingRepository->upsert($companyId, self::DEFAULT_SETTINGS);
        }

        return $this->formatAll($setting);
    }

    public function getStoreHours(int $companyId): array
    {
        $setting = $this->settingRepository->findByCompany($companyId);

        if (!$setting) {
            return [];
        }

        return $setting->store_hours ?? [];
    }

    public function updateSection(int $companyId, string $section, array $data): array
    {
        if (!isset(self::SECTION_COLUMN_MAP[$section])) {
            throw new HttpException(400, 'Invalid settings section');
        }

        $columnName = self::SECTION_COLUMN_MAP[$section];

        $setting = $this->settingRepository->upsert($companyId, [
            $columnName => $data,
        ]);

        return [
            $section => $data,
        ];
    }

    public function uploadLogo(int $companyId, $file, int $uploadedBy): array
    {
        $path = $file->store('uploads/logos', 'public');
        $url = Storage::url($path);

        $setting = $this->settingRepository->upsert($companyId, [
            'logo_url' => $url,
            'uploaded_by' => $uploadedBy,
        ]);

        return [
            'logoUrl' => $setting->logo_url,
            'uploadedAt' => $setting->updated_at,
        ];
    }

    public function uploadBanner(int $companyId, $file, int $uploadedBy): array
    {
        $path = $file->store('uploads/banners', 'public');
        $url = Storage::url($path);

        $setting = $this->settingRepository->upsert($companyId, [
            'banner_url' => $url,
            'uploaded_by' => $uploadedBy,
        ]);

        return [
            'bannerUrl' => $setting->banner_url,
            'uploadedAt' => $setting->updated_at,
        ];
    }

    public function uploadStorefrontImage($file): array
    {
        $path = $file->store('uploads/storefront', 'public');

        return [
            'imagePath' => $path,
            'imageUrl' => Storage::url($path),
        ];
    }

    private function formatAll($setting): array
    {
        return [
            'general' => $setting->general_settings ?? [],
            'tax' => $setting->tax_settings ?? [],
            'shipping' => $setting->shipping_settings ?? [],
            'payment' => $setting->payment_settings ?? [],
            'business' => $setting->business_settings ?? [],
            'regional' => $setting->regional_settings ?? [],
            'notifications' => $setting->notification_settings ?? [],
            'store-hours' => $setting->store_hours ?? [],
            'logoUrl' => $setting->logo_url,
            'bannerUrl' => $setting->banner_url,
            'uploadedBy' => $setting->uploaded_by,
        ];
    }
}
