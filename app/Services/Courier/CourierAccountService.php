<?php

namespace App\Services\Courier;

use App\DTOs\Shipping\CourierAccountMapper;
use App\Models\CourierAccount;
use App\Repositories\Contracts\ICourierAccountRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Courier integrations: connect / update / test merchant accounts (contract: COURIER_API_CONTRACT.md §2). */
class CourierAccountService
{
    private const SECRET_KEYS = ['apiKey', 'secretKey', 'clientSecret', 'password'];
    private const PLAIN_KEYS = ['clientId', 'username', 'storeId'];
    private const PATHAO_TOKEN_KEYS = ['clientId', 'clientSecret', 'username', 'password'];

    public function __construct(
        private readonly ICourierAccountRepository $repository,
        private readonly CourierManager $manager,
    ) {
    }

    public function list(int $companyId): array
    {
        return $this->repository->listByCompany($companyId)
            ->map(fn (CourierAccount $a) => CourierAccountMapper::toArray($a))->values()->all();
    }

    /** Active, integrated, configured accounts for the booking dialog (no webhook info). */
    public function listForBooking(int $companyId): array
    {
        return $this->repository->listByCompany($companyId, true)
            ->filter(fn (CourierAccount $a) => CourierManager::supports($a->providerKey()))
            ->map(fn (CourierAccount $a) => CourierAccountMapper::toArray($a, false))
            ->filter(fn (array $a) => $a['isConfigured'])
            ->values()->all();
    }

    public function find(int $id, int $companyId): CourierAccount
    {
        $a = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$a) {
            throw new HttpException(404, 'Courier account not found');
        }
        return $a;
    }

    public function get(int $id, int $companyId): array
    {
        return CourierAccountMapper::toArray($this->find($id, $companyId));
    }

    public function create(int $companyId, array $data): array
    {
        $provider = $this->providerKey((string) $data['provider']);
        if ($this->repository->findByProvider($companyId, $provider)) {
            throw new HttpException(422, CourierManager::label($provider) . ' is already connected. Edit the existing account instead.');
        }

        $creds = $this->mergeCredentials($provider, [], $data);
        if (CourierManager::supports($provider) && isset($data['credentials'])) {
            $this->assertRequired($provider, $creds);
        }

        $account = DB::transaction(function () use ($companyId, $provider, $data, $creds) {
            $hasOthers = $this->repository->listByCompany($companyId)->isNotEmpty();
            $makeDefault = (bool) ($data['isDefault'] ?? !$hasOthers);
            if ($makeDefault) {
                $this->repository->clearDefault($companyId);
            }
            return $this->repository->create([
                'company_id' => $companyId,
                'provider' => $provider,
                'account_id' => $data['accountId'] ?? null,
                'api_key' => $provider === CourierAccount::PROVIDER_STEADFAST ? null : ($data['apiKey'] ?? null),
                'credentials' => $creds ?: null,
                'environment' => ($data['environment'] ?? 'live') === 'sandbox' && $provider === CourierAccount::PROVIDER_PATHAO ? 'sandbox' : 'live',
                'is_active' => $data['isActive'] ?? true,
                'is_default' => $makeDefault,
                'settings' => $this->cleanSettings([], $data['settings'] ?? []),
                'webhook_token' => Str::random(40),
            ]);
        });

        return CourierAccountMapper::toArray($account);
    }

    public function update(int $id, int $companyId, array $data): array
    {
        $account = $this->find($id, $companyId);
        $provider = $account->providerKey();

        $patch = [];
        if (array_key_exists('accountId', $data)) {
            $patch['account_id'] = $data['accountId'];
        }
        if (array_key_exists('isActive', $data)) {
            $patch['is_active'] = (bool) $data['isActive'];
        }
        if (!empty($data['apiKey']) && $provider !== CourierAccount::PROVIDER_STEADFAST) {
            $patch['api_key'] = $data['apiKey'];
        }
        $old = $account->creds();
        $creds = $this->mergeCredentials($provider, $old, $data);
        if ($creds !== $old) {
            $patch['credentials'] = $creds;
            if (CourierManager::supports($provider)) {
                $this->assertRequired($provider, $creds, partialOk: true);
            }
        }
        if (array_key_exists('environment', $data) && $provider === CourierAccount::PROVIDER_PATHAO) {
            $patch['environment'] = $data['environment'] === 'sandbox' ? 'sandbox' : 'live';
        }
        if (array_key_exists('settings', $data) && is_array($data['settings'])) {
            $patch['settings'] = $this->cleanSettings(is_array($account->settings) ? $account->settings : [], $data['settings']);
        }

        // Anything that changes who we sign in as invalidates the cached token and the last test result
        $tokenChanged = ($patch['environment'] ?? $account->environment) !== $account->environment;
        foreach (self::PATHAO_TOKEN_KEYS as $k) {
            $tokenChanged = $tokenChanged || (($creds[$k] ?? null) !== ($old[$k] ?? null));
        }
        $secretChanged = false;
        foreach (['apiKey', 'secretKey'] as $k) {
            $secretChanged = $secretChanged || (($creds[$k] ?? null) !== ($old[$k] ?? null));
        }
        if ($tokenChanged || $secretChanged) {
            $patch += ['token_cache' => null, 'token_expires_at' => null, 'last_error' => null, 'last_checked_at' => null];
        }

        $account = DB::transaction(function () use ($account, $companyId, $patch, $data) {
            if (array_key_exists('isDefault', $data)) {
                if ($data['isDefault']) {
                    $this->repository->clearDefault($companyId, $account->id);
                }
                $patch['is_default'] = (bool) $data['isDefault'];
            }
            return $patch ? $this->repository->update($account, $patch) : $account;
        });

        return CourierAccountMapper::toArray($account);
    }

    public function setDefault(int $id, int $companyId): array
    {
        $account = $this->find($id, $companyId);
        DB::transaction(function () use ($account, $companyId) {
            $this->repository->clearDefault($companyId, $account->id);
            $this->repository->update($account, ['is_default' => true]);
        });
        return CourierAccountMapper::toArray($account->fresh());
    }

    public function delete(int $id, int $companyId): void
    {
        $account = $this->find($id, $companyId);
        if ($this->repository->hasOpenShipments($account)) {
            throw new HttpException(409, 'This courier still has parcels on the way. Turn it off instead, or wait until they are delivered or returned.');
        }
        $this->repository->delete($account);
    }

    /** Test the credentials; the result is saved on the account (lastCheckedAt / lastError). */
    public function test(int $id, int $companyId): array
    {
        $account = $this->find($id, $companyId);
        try {
            $result = $this->manager->driver($account)->testConnection();
            $account->forceFill(['last_checked_at' => now(), 'last_error' => null])->saveQuietly();
        } catch (CourierException $e) {
            $account->forceFill(['last_checked_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 500)])->saveQuietly();
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }

        return [
            'ok' => (bool) $result['ok'],
            'message' => (string) $result['message'],
            'balance' => $result['balance'] ?? null,
            'stores' => $result['stores'] ?? null,
            'account' => CourierAccountMapper::toArray($account->fresh()),
        ];
    }

    public function stores(int $id, int $companyId): array
    {
        return $this->manager->driver($this->find($id, $companyId))->stores();
    }

    public function locations(int $id, int $companyId, string $type, ?int $parentId): array
    {
        if (!in_array($type, ['city', 'zone', 'area'], true)) {
            throw new HttpException(422, 'type must be city, zone or area');
        }
        return $this->manager->driver($this->find($id, $companyId))->locations($type, $parentId);
    }

    public function webhookInfo(int $id, int $companyId, bool $rotate = false): array
    {
        $account = $this->find($id, $companyId);
        if ($rotate || !$account->webhook_token) {
            $account->forceFill(['webhook_token' => Str::random(40)])->saveQuietly();
        }
        $provider = $account->providerKey();
        $token = (string) $account->webhook_token;
        $url = CourierAccountMapper::webhookUrl($account);

        $instructions = match ($provider) {
            CourierAccount::PROVIDER_STEADFAST => 'In the Steadfast merchant panel open API / Webhook settings, paste the Callback URL, and set the Auth token (Bearer) to the token shown here. Status changes then arrive instantly; we also check every 15 minutes.',
            CourierAccount::PROVIDER_PATHAO => 'In the Pathao merchant panel open Developers API → Webhook, add the Callback URL and turn on order status events. If Pathao asks for a secret, use the token shown here. We also check every 15 minutes, so this is optional.',
            default => 'This courier is not integrated yet.',
        };

        return [
            'url' => $url,
            'provider' => $provider,
            'hasToken' => $token !== '',
            'token' => $token,
            'tokenHint' => CourierAccountMapper::maskEnd($token),
            'authHeader' => $provider === CourierAccount::PROVIDER_STEADFAST ? 'Bearer ' . $token : null,
            'instructions' => $instructions,
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function providerKey(string $provider): string
    {
        $key = strtolower(trim($provider));
        return CourierManager::supports($key) ? $key : trim($provider);
    }

    /** New credentials = old ones, with plain keys replaced when sent and secrets replaced only when non-blank. */
    private function mergeCredentials(string $provider, array $old, array $data): array
    {
        $in = is_array($data['credentials'] ?? null) ? $data['credentials'] : [];
        // Legacy body {apiKey} (old courier form) = Steadfast API key
        if ($provider === CourierAccount::PROVIDER_STEADFAST && !empty($data['apiKey']) && empty($in['apiKey'])) {
            $in['apiKey'] = $data['apiKey'];
        }
        $out = $old;
        foreach (self::PLAIN_KEYS as $k) {
            if (array_key_exists($k, $in)) {
                $v = $in[$k] === null ? null : trim((string) $in[$k]);
                if ($v === null || $v === '') {
                    unset($out[$k]);
                } else {
                    $out[$k] = $v;
                }
            }
        }
        foreach (self::SECRET_KEYS as $k) {
            if (isset($in[$k]) && trim((string) $in[$k]) !== '') {
                $out[$k] = trim((string) $in[$k]);
            }
        }
        return $out;
    }

    private function assertRequired(string $provider, array $creds, bool $partialOk = false): void
    {
        $required = match ($provider) {
            CourierAccount::PROVIDER_STEADFAST => ['apiKey' => 'API key', 'secretKey' => 'Secret key'],
            CourierAccount::PROVIDER_PATHAO => ['clientId' => 'Client ID', 'clientSecret' => 'Client secret', 'username' => 'Merchant email', 'password' => 'Password'],
            default => [],
        };
        $missing = [];
        foreach ($required as $k => $label) {
            if (empty($creds[$k])) {
                $missing["credentials.{$k}"] = ["{$label} is required"];
            }
        }
        if ($missing && !$partialOk) {
            throw \Illuminate\Validation\ValidationException::withMessages($missing);
        }
    }

    private function cleanSettings(array $old, array $in): array
    {
        $out = $old;
        foreach (['deliveryType' => 'int', 'itemType' => 'int', 'weightKg' => 'float', 'note' => 'string', 'itemDescription' => 'string'] as $k => $type) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = $in[$k];
            if ($v === null || $v === '') {
                unset($out[$k]);
                continue;
            }
            $out[$k] = match ($type) {
                'int' => (int) $v,
                'float' => round((float) $v, 2),
                default => mb_substr(trim((string) $v), 0, 250),
            };
        }
        return $out;
    }
}
