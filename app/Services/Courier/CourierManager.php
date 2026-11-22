<?php

namespace App\Services\Courier;

use App\Models\CourierAccount;
use App\Services\Courier\Drivers\PathaoDriver;
use App\Services\Courier\Drivers\SteadfastDriver;

/** Resolves the driver for a courier account. Add RedX / Paperfly here as new drivers. */
class CourierManager
{
    /** @var array<string, class-string<CourierDriver>> */
    private const DRIVERS = [
        CourierAccount::PROVIDER_PATHAO => PathaoDriver::class,
        CourierAccount::PROVIDER_STEADFAST => SteadfastDriver::class,
    ];

    public const LABELS = ['pathao' => 'Pathao', 'steadfast' => 'Steadfast'];

    public static function supports(string $provider): bool
    {
        return isset(self::DRIVERS[strtolower(trim($provider))]);
    }

    public static function label(string $provider): string
    {
        $key = strtolower(trim($provider));
        return self::LABELS[$key] ?? ucfirst($key);
    }

    public function driver(CourierAccount $account): CourierDriver
    {
        $class = self::DRIVERS[$account->providerKey()] ?? null;
        if (!$class) {
            throw new CourierException(self::label($account->provider) . ' is not integrated yet', 422, $account->providerKey(), true);
        }
        return new $class($account);
    }
}
