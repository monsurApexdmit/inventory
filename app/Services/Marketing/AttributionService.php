<?php

namespace App\Services\Marketing;

use App\Models\CampaignLink;

/**
 * Order attribution: which social post / ad / chat an order came from.
 *
 * Derivation (first match wins): campaign link code → utm_source → click id type → referrer host.
 * See AD_ORDERS_API_CONTRACT.md §5.
 */
class AttributionService
{
    public const AD_SOURCES = ['facebook', 'instagram', 'whatsapp', 'messenger', 'tiktok', 'google', 'youtube', 'phone', 'other'];

    /** Sources that count as "from ads / social" (filter value `any`). */
    public const SOCIAL_SOURCES = ['facebook', 'instagram', 'whatsapp', 'messenger', 'tiktok', 'google', 'youtube', 'other'];

    public const LABELS = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'whatsapp' => 'WhatsApp',
        'messenger' => 'Messenger',
        'tiktok' => 'TikTok',
        'google' => 'Google',
        'youtube' => 'YouTube',
        'phone' => 'Phone call',
        'other' => 'Other',
    ];

    private const UTM_MAP = [
        'facebook' => 'facebook', 'fb' => 'facebook', 'meta' => 'facebook', 'facebook.com' => 'facebook', 'm.facebook.com' => 'facebook',
        'l.facebook.com' => 'facebook', 'lm.facebook.com' => 'facebook', 'facebook_ads' => 'facebook', 'fb_ads' => 'facebook',
        'ig' => 'instagram', 'instagram' => 'instagram', 'instagram.com' => 'instagram', 'l.instagram.com' => 'instagram',
        'wa' => 'whatsapp', 'whatsapp' => 'whatsapp', 'wa.me' => 'whatsapp', 'whatsapp.com' => 'whatsapp',
        'messenger' => 'messenger', 'm.me' => 'messenger', 'fb_messenger' => 'messenger',
        'tiktok' => 'tiktok', 'tt' => 'tiktok', 'tiktok.com' => 'tiktok', 'tiktok_ads' => 'tiktok',
        'google' => 'google', 'gads' => 'google', 'adwords' => 'google', 'google_ads' => 'google', 'googleads' => 'google', 'google.com' => 'google',
        'youtube' => 'youtube', 'yt' => 'youtube', 'youtube.com' => 'youtube',
    ];

    private const CLICK_ID_MAP = ['fbclid' => 'facebook', 'gclid' => 'google', 'gbraid' => 'google', 'wbraid' => 'google', 'ttclid' => 'tiktok'];

    public static function label(?string $source): ?string
    {
        return $source ? (self::LABELS[$source] ?? ucfirst($source)) : null;
    }

    /** Manual "Source" select value → stored ad_source (direct / walk-in / empty → null). */
    public static function normalizeManual(?string $value): ?string
    {
        $v = strtolower(trim((string) $value));
        if ($v === '' || in_array($v, ['direct', 'walk-in', 'walkin', 'walk_in', 'none'], true)) {
            return null;
        }

        return in_array($v, self::AD_SOURCES, true) ? $v : null;
    }

    /**
     * Raw attribution (camelCase or snake_case keys) → sells columns.
     * Always returns every key (null when unknown) so it can be merged straight into a Sell::create payload.
     *
     * @return array{ad_source:?string, utm_source:?string, utm_medium:?string, utm_campaign:?string, utm_content:?string,
     *               utm_term:?string, click_id:?string, campaign_link_id:?int, landing_url:?string, referrer:?string}
     */
    public function fromInput(int $companyId, ?array $raw, ?string $storeHost = null): array
    {
        $raw = is_array($raw) ? $raw : [];
        $get = function (string ...$keys) use ($raw): ?string {
            foreach ($keys as $k) {
                $v = $raw[$k] ?? null;
                if (is_scalar($v) && trim((string) $v) !== '') {
                    return trim((string) $v);
                }
            }
            return null;
        };

        $landing = $this->cut($get('landingUrl', 'landing_url'), 500);
        $referrer = $this->cut($get('referrer', 'referer'), 500);

        // utm_* / click ids missing from the payload are read from the landing URL's query string
        $landingQuery = [];
        if ($landing) {
            $q = parse_url($landing, PHP_URL_QUERY);
            if (is_string($q)) {
                parse_str($q, $landingQuery);
            }
        }
        $fromLanding = fn (string $k) => is_scalar($landingQuery[$k] ?? null) && trim((string) $landingQuery[$k]) !== '' ? trim((string) $landingQuery[$k]) : null;

        $out = [
            'utm_source' => $get('utmSource', 'utm_source') ?? $fromLanding('utm_source'),
            'utm_medium' => $get('utmMedium', 'utm_medium') ?? $fromLanding('utm_medium'),
            'utm_campaign' => $get('utmCampaign', 'utm_campaign') ?? $fromLanding('utm_campaign'),
            'utm_content' => $get('utmContent', 'utm_content') ?? $fromLanding('utm_content'),
            'utm_term' => $get('utmTerm', 'utm_term') ?? $fromLanding('utm_term'),
        ];
        foreach ($out as $k => $v) {
            $out[$k] = $this->cut($v, 100);
        }

        // Click id + its type
        $clickId = $get('clickId', 'click_id');
        $clickType = strtolower((string) $get('clickIdType', 'click_id_type'));
        if (!isset(self::CLICK_ID_MAP[$clickType])) {
            $clickType = '';
        }
        foreach (array_keys(self::CLICK_ID_MAP) as $param) {
            $v = $get($param) ?? $fromLanding($param);
            if ($v !== null) {
                $clickId ??= $v;
                if ($clickType === '') {
                    $clickType = $param;
                }
                break;
            }
        }
        $out['click_id'] = $this->cut($clickId, 255);
        $out['landing_url'] = $landing;
        $out['referrer'] = $referrer;
        $out['campaign_link_id'] = null;

        // 1. Campaign link
        $code = strtolower((string) ($get('campaignCode', 'campaign_code', 'cl') ?? $fromLanding('cl') ?? ''));
        $link = $code !== '' ? CampaignLink::withTrashed()->where('company_id', $companyId)->where('code', $code)->first() : null;
        if ($link) {
            $out['campaign_link_id'] = $link->id;
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'] as $k) {
                $out[$k] ??= $link->{$k};
            }
            $out['ad_source'] = in_array($link->channel, self::AD_SOURCES, true) ? $link->channel : 'other';

            return $out;
        }

        // 2. utm_source
        if ($out['utm_source'] !== null) {
            $out['ad_source'] = self::UTM_MAP[strtolower($out['utm_source'])] ?? 'other';

            return $out;
        }

        // 3. Click id type
        if ($out['click_id'] !== null && isset(self::CLICK_ID_MAP[$clickType])) {
            $out['ad_source'] = self::CLICK_ID_MAP[$clickType];

            return $out;
        }

        // 4. Referrer host
        $out['ad_source'] = $this->fromReferrer($referrer, $storeHost);

        return $out;
    }

    public function fromReferrer(?string $referrer, ?string $storeHost = null): ?string
    {
        if (!$referrer) {
            return null;
        }
        $host = strtolower((string) parse_url(str_contains($referrer, '://') ? $referrer : 'https://' . $referrer, PHP_URL_HOST));
        if ($host === '' || ($storeHost && $host === strtolower($storeHost))) {
            return null;
        }
        $host = preg_replace('/^www\./', '', $host);
        $is = fn (string $domain) => $host === $domain || str_ends_with($host, '.' . $domain);

        return match (true) {
            $is('messenger.com') || $host === 'm.me' => 'messenger',
            $is('facebook.com') || $is('fb.com') || $is('fb.me') => 'facebook',
            $is('instagram.com') => 'instagram',
            $is('wa.me') || $is('whatsapp.com') => 'whatsapp',
            $is('tiktok.com') => 'tiktok',
            $is('youtube.com') || $is('youtu.be') => 'youtube',
            (bool) preg_match('/(^|\.)google\.[a-z.]+$/', $host) => 'google',
            default => null,
        };
    }

    private function cut(?string $v, int $max): ?string
    {
        return $v === null ? null : mb_substr($v, 0, $max);
    }
}
