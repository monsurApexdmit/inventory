<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A company's merchant account at a courier (Pathao, Steadfast, …).
 * Secrets live in `credentials` (encrypted JSON) and `token_cache` (encrypted); neither is ever serialized.
 */
class CourierAccount extends Model
{
    public const PROVIDER_PATHAO = 'pathao';
    public const PROVIDER_STEADFAST = 'steadfast';
    public const PROVIDERS = [self::PROVIDER_PATHAO, self::PROVIDER_STEADFAST];

    protected $fillable = [
        'company_id', 'provider', 'account_id', 'api_key', 'credentials', 'environment', 'is_active', 'is_default',
        'settings', 'webhook_token', 'token_cache', 'token_expires_at', 'last_checked_at', 'last_error',
    ];

    protected $hidden = ['api_key', 'credentials', 'token_cache', 'webhook_token'];

    protected $casts = [
        'api_key'          => 'encrypted',
        'credentials'      => 'encrypted:array',
        'token_cache'      => 'encrypted',
        'settings'         => 'array',
        'is_active'        => 'boolean',
        'is_default'       => 'boolean',
        'token_expires_at' => 'datetime',
        'last_checked_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (CourierAccount $a) {
            if (empty($a->webhook_token)) {
                $a->webhook_token = Str::random(40);
            }
        });
    }

    /** Lower-case provider key ("Pathao" saved by the old UI → "pathao"). */
    public function providerKey(): string
    {
        return strtolower(trim((string) $this->provider));
    }

    /** Decrypted credentials merged with the legacy single api_key column. */
    public function creds(): array
    {
        $c = is_array($this->credentials) ? $this->credentials : [];
        if ($this->providerKey() === self::PROVIDER_STEADFAST && empty($c['apiKey']) && !empty($this->api_key)) {
            $c['apiKey'] = $this->api_key;
        }
        return $c;
    }

    public function cred(string $key): ?string
    {
        $v = $this->creds()[$key] ?? null;
        return $v === null || $v === '' ? null : (string) $v;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $s = is_array($this->settings) ? $this->settings : [];
        return array_key_exists($key, $s) && $s[$key] !== null && $s[$key] !== '' ? $s[$key] : $default;
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(OrderShipment::class, 'courier_account_id');
    }
}
