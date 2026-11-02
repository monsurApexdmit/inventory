<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Setting;

/**
 * Public base URL of a company's storefront (no trailing slash):
 *  - Online store setting `onlineStore.slug` when it is a domain (contains a dot) → https://<domain>  (same rule as admin "View store")
 *  - else the company subdomain through STOREFRONT_URL_PATTERN (default http://{subdomain}.localhost:8081)
 *  - else null
 */
class StorefrontUrl
{
    /** @var array<int, ?string> */
    private static array $cache = [];

    public static function base(int $companyId): ?string
    {
        if (array_key_exists($companyId, self::$cache)) {
            return self::$cache[$companyId];
        }

        $business = Setting::where('company_id', $companyId)->first()?->business_settings ?? [];
        $slug = is_array($business['onlineStore'] ?? null) ? trim((string) ($business['onlineStore']['slug'] ?? '')) : '';
        if ($slug !== '' && str_contains($slug, '.')) {
            return self::$cache[$companyId] = preg_match('#^https?://#i', $slug) ? rtrim($slug, '/') : 'https://' . rtrim($slug, '/');
        }

        $sub = trim((string) Company::where('id', $companyId)->value('subdomain'));
        if ($sub === '') {
            return self::$cache[$companyId] = null;
        }
        $pattern = (string) config('app.storefront_url_pattern', 'http://{subdomain}.localhost:8081');

        return self::$cache[$companyId] = rtrim(str_replace('{subdomain}', $sub, $pattern), '/');
    }
}
