<?php

namespace App\DTOs\Shipping;

use App\Models\CourierAccount;
use App\Services\Courier\CourierManager;

/** CourierAccount → API array. Secrets are never included: masked values + hasX flags only. */
final class CourierAccountMapper
{
    public static function toArray(CourierAccount $a, bool $withWebhook = true): array
    {
        $provider = $a->providerKey();
        $c = $a->creds();
        $has = fn (string $k) => isset($c[$k]) && $c[$k] !== '' && $c[$k] !== null;
        $legacyKey = (string) ($a->api_key ?? '');

        $credentials = [];
        $flags = [];
        if ($provider === CourierAccount::PROVIDER_PATHAO) {
            $credentials = [
                'clientId' => $has('clientId') ? self::maskStart((string) $c['clientId']) : null,
                'username' => $has('username') ? (string) $c['username'] : null,
                'storeId' => $has('storeId') ? (string) $c['storeId'] : null,
            ];
            $flags = ['hasClientId' => $has('clientId'), 'hasClientSecret' => $has('clientSecret'), 'hasPassword' => $has('password')];
            $configured = $has('clientId') && $has('clientSecret') && $has('username') && $has('password');
        } elseif ($provider === CourierAccount::PROVIDER_STEADFAST) {
            $credentials = ['apiKey' => $has('apiKey') ? self::maskEnd((string) $c['apiKey']) : null];
            $flags = ['hasApiKey' => $has('apiKey'), 'hasSecretKey' => $has('secretKey')];
            $configured = $has('apiKey') && $has('secretKey');
        } else {
            $configured = false;
        }
        $anySecret = $legacyKey !== '' || $has('apiKey') || $has('secretKey') || $has('clientSecret') || $has('password');

        $settings = is_array($a->settings) ? $a->settings : [];
        $out = [
            'id' => $a->id,
            'provider' => $provider,
            'label' => CourierManager::label($provider),
            'isIntegrated' => CourierManager::supports($provider),
            'environment' => $provider === CourierAccount::PROVIDER_PATHAO ? ($a->environment === 'sandbox' ? 'sandbox' : 'live') : 'live',
            'isActive' => (bool) $a->is_active,
            'isDefault' => (bool) $a->is_default,
            'accountId' => $a->account_id,
            'credentials' => $credentials,
        ] + $flags + [
            'hasKey' => $anySecret,
            'apiKey' => $legacyKey !== '' ? self::maskEnd($legacyKey) : ($has('apiKey') ? self::maskEnd((string) $c['apiKey']) : null),
            'isConfigured' => $configured,
            'needsStore' => $provider === CourierAccount::PROVIDER_PATHAO && !$has('storeId'),
            'settings' => [
                'deliveryType' => isset($settings['deliveryType']) ? (int) $settings['deliveryType'] : 48,
                'itemType' => isset($settings['itemType']) ? (int) $settings['itemType'] : 2,
                'weightKg' => isset($settings['weightKg']) ? (float) $settings['weightKg'] : 0.5,
                'note' => $settings['note'] ?? null,
                'itemDescription' => $settings['itemDescription'] ?? null,
            ],
            'connectionStatus' => $a->last_error ? 'error' : ($a->last_checked_at ? 'connected' : 'untested'),
            'lastCheckedAt' => $a->last_checked_at?->toIso8601String(),
            'lastError' => $a->last_error,
            'createdAt' => $a->created_at?->toIso8601String(),
            'updatedAt' => $a->updated_at?->toIso8601String(),
        ];
        if ($withWebhook) {
            $out['webhookUrl'] = CourierManager::supports($provider) ? self::webhookUrl($a) : null;
        }
        return $out;
    }

    public static function webhookUrl(CourierAccount $a): ?string
    {
        if (!$a->webhook_token) {
            return null;
        }
        $base = rtrim((string) (config('couriers.webhook_base_url') ?: config('app.url')), '/');
        return "{$base}/api/webhooks/courier/{$a->providerKey()}/{$a->webhook_token}";
    }

    public static function maskEnd(string $v): string
    {
        return strlen($v) > 8 ? str_repeat('•', 8) . substr($v, -4) : str_repeat('•', 8);
    }

    public static function maskStart(string $v): string
    {
        return strlen($v) > 8 ? substr($v, 0, 4) . str_repeat('•', 6) : str_repeat('•', 8);
    }
}
