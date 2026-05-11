<?php

namespace App\Services\Gateway;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PayPalService
{
    private string $clientId;
    private string $clientSecret;
    private string $baseUrl;

    public function __construct(int $companyId)
    {
        $setting = Setting::where('company_id', $companyId)->first();
        $config  = $setting?->payment_settings['paypal'] ?? [];

        if (empty($config['client_id']) || empty($config['client_secret'])) {
            throw new HttpException(500, 'PayPal credentials not configured');
        }

        $sandbox        = (bool) ($config['sandbox'] ?? true);
        $this->clientId     = $config['client_id'];
        $this->clientSecret = $config['client_secret'];
        $this->baseUrl      = $sandbox
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    /**
     * Create a PayPal order and return the approval URL.
     */
    public function initPayment(array $params): string
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/v2/checkout/orders", [
                'intent'         => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $params['tran_id'],
                    'amount'       => [
                        'currency_code' => strtoupper($params['currency'] ?? 'USD'),
                        'value'         => number_format((float) $params['amount'], 2, '.', ''),
                    ],
                    'description'  => $params['product_name'] ?? 'Order',
                ]],
                'application_context' => [
                    'return_url' => $params['success_url'],
                    'cancel_url' => $params['cancel_url'],
                    'brand_name' => $params['brand_name'] ?? 'Shop',
                    'user_action'=> 'PAY_NOW',
                ],
            ]);

        if (!$response->successful()) {
            throw new HttpException(502, 'PayPal order creation failed: ' . $response->body());
        }

        $links = $response->json('links') ?? [];
        foreach ($links as $link) {
            if ($link['rel'] === 'approve') {
                return $link['href'];
            }
        }

        throw new HttpException(502, 'PayPal approval URL not found in response');
    }

    /**
     * Capture a PayPal order after buyer approval.
     */
    public function captureOrder(string $orderId): array
    {
        $token    = $this->getAccessToken();
        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/v2/checkout/orders/{$orderId}/capture");

        if (!$response->successful()) {
            throw new HttpException(502, 'PayPal capture failed: ' . $response->body());
        }

        return $response->json();
    }

    private function getAccessToken(): string
    {
        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->asForm()
            ->post("{$this->baseUrl}/v1/oauth2/token", ['grant_type' => 'client_credentials']);

        if (!$response->successful()) {
            throw new HttpException(502, 'PayPal auth failed');
        }

        return $response->json('access_token');
    }
}
