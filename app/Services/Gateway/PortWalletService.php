<?php

namespace App\Services\Gateway;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PortWalletService
{
    private string $appKey;
    private string $appSecret;
    private bool   $sandbox;
    private string $baseUrl;

    public function __construct(int $companyId)
    {
        $setting = Setting::where('company_id', $companyId)->first();
        $config  = $setting?->payment_settings['portwallet'] ?? [];

        if (empty($config['app_key']) || empty($config['app_secret'])) {
            throw new HttpException(500, 'PortWallet credentials not configured');
        }

        $this->appKey    = $config['app_key'];
        $this->appSecret = $config['app_secret'];
        $this->sandbox   = (bool) ($config['sandbox'] ?? true);
        $this->baseUrl   = $this->sandbox
            ? 'https://sandbox.portwallet.com'
            : 'https://api.portwallet.com';
    }

    /**
     * Initiate a payment. Returns the gateway payment URL.
     */
    public function initPayment(array $params): string
    {
        $payload = [
            'amount'          => $params['amount'],
            'currency'        => $params['currency'] ?? 'BDT',
            'invoice'         => $params['tran_id'],
            'product_name'    => $params['product_name'] ?? 'Order',
            'product_type'    => 'general',
            'customer_name'   => $params['customer_name'],
            'customer_email'  => $params['customer_email']  ?? 'noreply@shop.com',
            'customer_phone'  => $params['customer_phone']  ?? '01700000000',
            'customer_address'=> $params['shipping_address'] ?? 'N/A',
            'customer_city'   => $params['shipping_city']   ?? 'Dhaka',
            'customer_country'=> 'Bangladesh',
            'success_url'     => $params['success_url'],
            'failure_url'     => $params['fail_url'],
            'cancel_url'      => $params['cancel_url'],
            'ipn_url'         => $params['ipn_url'],
        ];

        $hash = $this->generateHash($payload);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->appKey}:{$hash}",
            'Content-Type'  => 'application/json',
        ])->post("{$this->baseUrl}/payment/create", $payload);

        if (!$response->successful() || $response->json('status') !== 1) {
            throw new HttpException(502, 'PortWallet payment init failed: ' . ($response->json('message') ?? 'unknown'));
        }

        return $response->json('data.payment_url');
    }

    /**
     * Verify a PortWallet callback by checking the hash.
     */
    public function verifyCallback(array $data): bool
    {
        if (empty($data['hash']) || empty($data['invoice'])) {
            return false;
        }

        $expected = hash_hmac('sha256', $data['invoice'] . $data['amount'], $this->appSecret);

        return hash_equals($expected, $data['hash']);
    }

    private function generateHash(array $payload): string
    {
        $str = $payload['invoice'] . $payload['amount'] . $payload['currency'];
        return hash_hmac('sha256', $str, $this->appSecret);
    }
}
