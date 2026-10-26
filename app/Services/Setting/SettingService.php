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

    /** Sections written by several screens; updates are merged key-by-key (top level). */
    private const MERGED_SECTIONS = ['general', 'tax', 'shipping', 'payment', 'business', 'notifications'];

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

    /**
     * Payment methods for the POS: enabled methods with their channels (missing channels = both),
     * the default slug and the slugs usable at the POS. Account fields are not exposed.
     */
    public function getPaymentMethods(int $companyId): array
    {
        $pay = $this->settingRepository->findByCompany($companyId)?->payment_settings;
        $pay = is_array($pay) ? $pay : [];
        $labels = \App\Http\Requests\Setting\UpdatePaymentSettingsRequest::METHODS;
        $methods = [];
        foreach (($pay['methods'] ?? []) as $slug => $m) {
            if (!is_array($m) || !filter_var($m['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $channels = isset($m['channels']) && is_array($m['channels']) ? array_values($m['channels']) : ['pos', 'online'];
            $methods[] = ['slug' => (string) $slug, 'name' => $labels[$slug][0] ?? ucfirst((string) $slug), 'channels' => $channels];
        }
        $pos = array_values(array_map(fn ($m) => $m['slug'], array_filter($methods, fn ($m) => in_array('pos', $m['channels'], true))));
        $default = $pay['default'] ?? null;

        return [
            'methods' => $methods,
            'posMethods' => $pos,
            'default' => $default,
            'posDefault' => in_array($default, $pos, true) ? $default : ($pos[0] ?? null),
        ];
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

        if (in_array($section, self::MERGED_SECTIONS, true)) {
            // Merge so partial writers (General settings vs Aura Shop vs Company settings colours,
            // payment gateways vs payment toggles...) don't wipe each other's keys.
            $existing = $this->settingRepository->findByCompany($companyId)?->{$columnName};
            $data = array_merge(is_array($existing) ? $existing : [], $data);
        }

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

    public function updateBranding(int $companyId, array $data): array
    {
        $patch = [];
        if (array_key_exists('logoUrl', $data)) {
            $patch['logo_url'] = $data['logoUrl'];
        }
        if (array_key_exists('bannerUrl', $data)) {
            $patch['banner_url'] = $data['bannerUrl'];
        }
        $setting = $patch ? $this->settingRepository->upsert($companyId, $patch) : $this->settingRepository->findByCompany($companyId);

        return ['logoUrl' => $setting?->logo_url, 'bannerUrl' => $setting?->banner_url];
    }

    public function removeLogo(int $companyId): array
    {
        $setting = $this->settingRepository->upsert($companyId, ['logo_url' => null]);

        return ['logoUrl' => $setting->logo_url];
    }

    public function removeBanner(int $companyId): array
    {
        $setting = $this->settingRepository->upsert($companyId, ['banner_url' => null]);

        return ['bannerUrl' => $setting->banner_url];
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
            'payment' => array_merge(['cod_limit' => 5000], $setting->payment_settings ?? []),
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
