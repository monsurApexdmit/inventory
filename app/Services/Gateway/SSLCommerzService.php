<?php

namespace App\Services\Gateway;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SSLCommerzService
{
    private string $storeId;
    private string $storePasswd;
    private bool   $sandbox;
    private string $baseUrl;

    public function __construct(int $companyId)
    {
        $setting  = Setting::where('company_id', $companyId)->first();
        $config   = $setting?->payment_settings['sslcommerz'] ?? [];

        if (empty($config['store_id']) || empty($config['store_passwd'])) {
            throw new HttpException(500, 'SSLCommerz credentials not configured');
        }

        $this->storeId     = $config['store_id'];
        $this->storePasswd = $config['store_passwd'];
        $this->sandbox     = (bool) ($config['sandbox'] ?? true);
        $this->baseUrl     = $this->sandbox
            ? 'https://sandbox.sslcommerz.com'
            : 'https://securepay.sslcommerz.com';
    }

    /**
     * Initiate a payment session. Returns the gateway payment URL.
     */
    public function initPayment(array $params): string
    {
        $response = Http::asForm()->post("{$this->baseUrl}/gwprocess/v4/api.php", array_merge([
            'store_id'       => $this->storeId,
            'store_passwd'   => $this->storePasswd,
            'total_amount'   => $params['amount'],
            'currency'       => $params['currency'] ?? 'BDT',
            'tran_id'        => $params['tran_id'],
            'success_url'    => $params['success_url'],
            'fail_url'       => $params['fail_url'],
            'cancel_url'     => $params['cancel_url'],
            'ipn_url'        => $params['ipn_url'],
            'cus_name'       => $params['customer_name'],
            'cus_email'      => $params['customer_email'] ?? 'noreply@shop.com',
            'cus_phone'      => $params['customer_phone'] ?? '01700000000',
            'cus_add1'       => $params['shipping_address'] ?? 'N/A',
            'cus_city'       => $params['shipping_city']    ?? 'Dhaka',
            'cus_country'    => 'Bangladesh',
            'shipping_method'=> 'NO',
            'product_name'   => $params['product_name']    ?? 'Order',
            'product_category'=> 'General',
            'product_profile'=> 'general',
        ]));

        if (!$response->successful() || ($response->json('status') !== 'SUCCESS')) {
            throw new HttpException(502, 'SSLCommerz session init failed: ' . ($response->json('failedreason') ?? 'unknown'));
        }

        return $response->json('GatewayPageURL');
    }

    /**
     * Validate IPN / success callback. Returns true if payment is valid.
     */
    public function validatePayment(string $valId, float $amount, string $currency = 'BDT'): bool
    {
        $response = Http::get("{$this->baseUrl}/validator/api/validationserverAPI.php", [
            'val_id'      => $valId,
            'store_id'    => $this->storeId,
            'store_passwd'=> $this->storePasswd,
            'format'      => 'json',
        ]);

        if (!$response->successful()) {
            return false;
        }

        $data = $response->json();

        if ($data['status'] !== 'VALID' && $data['status'] !== 'VALIDATED') {
            return false;
        }

        // Verify amount matches (allow ±1 BDT tolerance for rounding)
        if (abs((float) $data['amount'] - $amount) > 1) {
            return false;
        }

        if ($data['currency_type'] !== $currency) {
            return false;
        }

        return true;
    }
}
