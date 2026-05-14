<?php

namespace App\Services\Gateway;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

class NagadService
{
    private string $baseUrl;
    private string $merchantId;
    private string $privateKey;  // PEM, no header/footer
    private string $publicKey;   // Nagad's public key for encrypting sensitive data

    // Nagad's sandbox public key (from sandbox PG)
    private const SANDBOX_NAGAD_PUBLIC_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAjBH1pFNSSRKPuMcNxmU5jZ1x8K9LPFM4XSu11m7uCfLUSE4SEjL30w3ockFvwAcuJffCUwtSpbjr34cSTD7EFG1Jqk9Gg0fQCKvPaU54jjMJoP2toR9fGmQV7y9fz31UVxSk97AqWZZLJBT2lmv76AgpVV0k0xtb/0VIv8pd/j6TIz9SFfsTQOugHkhyRzzhvZisiKzOAAWNX8RMpG+iqQi4p9W9VrmmiCfFDmLFnMrwhncnMsvlXB8QSJCq2irrx3HG0SJJCbS5+atz+E1iqO8QaPJ05snxv82Mf4NlZ4gZK0Pq/VvJ20lSkR+0nk+s/v3BgIyle78wjZP1vWLU4wIDAQAB';

    public function __construct(int $companyId)
    {
        $setting = Setting::where('company_id', $companyId)->first();
        $config  = $setting?->payment_settings['nagad'] ?? [];

        $sandbox          = (bool) ($config['sandbox'] ?? true);
        $this->baseUrl    = $sandbox
            ? 'https://sandbox.mynagad.com:10080/remote-payment-gateway-1.0'
            : 'https://api.mynagad.com/api/dfs';
        $this->merchantId = $config['merchant_id'] ?? '';
        $this->privateKey = $config['private_key'] ?? '';
        $this->publicKey  = $config['nagad_public_key'] ?? self::SANDBOX_NAGAD_PUBLIC_KEY;
    }

    private function sign(string $data): string
    {
        $pem = "-----BEGIN RSA PRIVATE KEY-----\n"
            . wordwrap($this->privateKey, 64, "\n", true)
            . "\n-----END RSA PRIVATE KEY-----";

        $key = openssl_pkey_get_private($pem);
        if (!$key) {
            throw new \RuntimeException('Invalid Nagad private key');
        }

        openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    private function encrypt(string $data): string
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . wordwrap($this->publicKey, 64, "\n", true)
            . "\n-----END PUBLIC KEY-----";

        $key = openssl_pkey_get_public($pem);
        if (!$key) {
            throw new \RuntimeException('Invalid Nagad public key');
        }

        openssl_public_encrypt($data, $encrypted, $key, OPENSSL_PKCS1_OAEP_PADDING);
        return base64_encode($encrypted);
    }

    /**
     * Create payment and return the Nagad redirect URL.
     */
    public function initPayment(array $params): string
    {
        $orderId   = $params['tran_id'];
        $amount    = number_format((float) $params['amount'], 2, '.', '');
        $timestamp = now()->format('YmdHis');

        $sensitiveData = json_encode([
            'merchantId'        => $this->merchantId,
            'datetime'          => $timestamp,
            'orderId'           => $orderId,
            'challenge'         => $orderId,
        ]);

        $checkoutInit = [
            'datetime'          => $timestamp,
            'shopId'            => $this->merchantId,
            'merchantId'        => $this->merchantId,
            'orderId'           => $orderId,
            'amount'            => $amount,
            'currencyCode'      => '050',
            'instrumentType'    => 'Nagad',
            'sensitiveData'     => $this->encrypt($sensitiveData),
            'signature'         => $this->sign($sensitiveData),
            'callbackURL'       => $params['callback_url'],
        ];

        $res = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post("{$this->baseUrl}/api/dfs/check-out/initialize/{$this->merchantId}/{$orderId}", $checkoutInit);

        if (!$res->successful()) {
            throw new \RuntimeException('Nagad init failed: ' . $res->body());
        }

        $data = $res->json();

        // Decode and validate sensitive data from Nagad response
        $paymentReferenceId = $data['paymentReferenceId'] ?? null;
        $urlToCall          = $data['callBackUrl']        ?? null;

        if (!$paymentReferenceId || !$urlToCall) {
            throw new \RuntimeException('Nagad init missing payment reference: ' . $res->body());
        }

        $completeData = [
            'paymentRefId'      => $paymentReferenceId,
            'sensitiveData'     => $this->encrypt(json_encode([
                'merchantId'    => $this->merchantId,
                'orderId'       => $orderId,
                'amount'        => $amount,
                'currencyCode'  => '050',
                'challenge'     => $paymentReferenceId,
            ])),
            'signature'         => $this->sign($paymentReferenceId),
            'merchantCallbackURL' => $params['callback_url'],
        ];

        $res2 = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post("{$this->baseUrl}/api/dfs/check-out/complete/{$paymentReferenceId}", $completeData);

        if (!$res2->successful()) {
            throw new \RuntimeException('Nagad complete failed: ' . $res2->body());
        }

        $callbackUrl = $res2->json('callBackUrl') ?? null;
        if (!$callbackUrl) {
            throw new \RuntimeException('Nagad did not return a callBackUrl: ' . $res2->body());
        }

        return $callbackUrl;
    }

    /**
     * Verify callback by querying transaction status.
     */
    public function verifyPayment(string $paymentRefId): string
    {
        $res = Http::withHeaders(['Content-Type' => 'application/json'])
            ->get("{$this->baseUrl}/api/dfs/verify/payment/{$paymentRefId}");

        if (!$res->successful()) {
            throw new \RuntimeException('Nagad verify failed: ' . $res->body());
        }

        $status = $res->json('status') ?? '';
        if ($status !== 'Success') {
            throw new \RuntimeException("Nagad payment status: {$status}");
        }

        return $res->json('issuerPaymentRefNo') ?? $paymentRefId;
    }
}
