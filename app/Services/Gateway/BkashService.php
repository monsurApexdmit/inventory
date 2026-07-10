<?php

namespace App\Services\Gateway;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class BkashService
{
    private string $baseUrl;
    private string $appKey;
    private string $appSecret;
    private string $username;
    private string $password;
    private bool   $sandbox;

    public function __construct(int $companyId)
    {
        $setting = Setting::where('company_id', $companyId)->first();
        $config  = $setting?->payment_settings['bkash'] ?? [];

        $this->sandbox   = (bool) ($config['sandbox'] ?? true);
        $this->baseUrl   = $this->sandbox
            ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta'
            : 'https://tokenized.pay.bka.sh/v1.2.0-beta';
        $this->appKey    = $config['app_key']    ?? '';
        $this->appSecret = $config['app_secret'] ?? '';
        $this->username  = $config['username']   ?? '';
        $this->password  = $config['password']   ?? '';
    }

    private function getToken(): string
    {
        $cacheKey = "bkash_token_{$this->appKey}";

        return Cache::remember($cacheKey, 3500, function () {
            $res = Http::withHeaders([
                'Content-Type'  => 'application/json',
                'username'      => $this->username,
                'password'      => $this->password,
            ])->post("{$this->baseUrl}/tokenized/checkout/token/grant", [
                'app_key'    => $this->appKey,
                'app_secret' => $this->appSecret,
            ]);

            if (!$res->successful()) {
                throw new \RuntimeException('bKash token grant failed: ' . $res->body());
            }

            return $res->json('id_token');
        });
    }

    /**
     * Create payment and return the bKash payment URL.
     */
    public function initPayment(array $params): string
    {
        $token = $this->getToken();

        $res = Http::withHeaders([
            'Content-Type'  => 'application/json',
            'Authorization' => $token,
            'X-APP-Key'     => $this->appKey,
        ])->post("{$this->baseUrl}/tokenized/checkout/create", [
            'mode'                => '0011',  // Checkout URL mode
            'payerReference'      => $params['tran_id'],
            'callbackURL'         => $params['callback_url'],
            'amount'              => number_format((float) $params['amount'], 2, '.', ''),
            'currency'            => 'BDT',
            'intent'              => 'sale',
            'merchantInvoiceNumber' => $params['tran_id'],
        ]);

        if (!$res->successful() || $res->json('statusCode') !== '0000') {
            throw new \RuntimeException('bKash create payment failed: ' . $res->body());
        }

        return $res->json('bkashURL');
    }

    /**
     * Execute payment after user authorizes. Returns transaction ID on success.
     */
    public function executePayment(string $paymentId): string
    {
        $token = $this->getToken();

        $res = Http::withHeaders([
            'Content-Type'  => 'application/json',
            'Authorization' => $token,
            'X-APP-Key'     => $this->appKey,
        ])->post("{$this->baseUrl}/tokenized/checkout/execute", [
            'paymentID' => $paymentId,
        ]);

        if (!$res->successful() || $res->json('statusCode') !== '0000') {
            throw new \RuntimeException('bKash execute failed: ' . ($res->json('statusMessage') ?? $res->body()));
        }

        return $res->json('trxID');
    }
}
