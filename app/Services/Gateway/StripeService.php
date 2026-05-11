<?php

namespace App\Services\Gateway;

use App\Models\Setting;
use Stripe\StripeClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StripeService
{
    private StripeClient $stripe;
    private string $webhookSecret;

    public function __construct(int $companyId)
    {
        $setting = Setting::where('company_id', $companyId)->first();
        $config  = $setting?->payment_settings['stripe'] ?? [];

        if (empty($config['secret_key'])) {
            throw new HttpException(500, 'Stripe credentials not configured');
        }

        $this->stripe        = new StripeClient($config['secret_key']);
        $this->webhookSecret = $config['webhook_secret'] ?? '';
    }

    /**
     * Create a Stripe Checkout Session. Returns the session URL.
     */
    public function initPayment(array $params): string
    {
        $session = $this->stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'line_items'           => [[
                'price_data' => [
                    'currency'     => strtolower($params['currency'] ?? 'usd'),
                    'product_data' => ['name' => $params['product_name'] ?? 'Order'],
                    'unit_amount'  => (int) round($params['amount'] * 100), // cents
                ],
                'quantity' => 1,
            ]],
            'mode'        => 'payment',
            'success_url' => $params['success_url'] . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $params['cancel_url'],
            'metadata'    => ['tran_id' => $params['tran_id']],
            'customer_email' => $params['customer_email'] ?? null,
        ]);

        return $session->url;
    }

    /**
     * Retrieve a completed checkout session to verify payment.
     */
    public function getSession(string $sessionId): object
    {
        return $this->stripe->checkout->sessions->retrieve($sessionId);
    }

    /**
     * Validate a Stripe webhook signature. Returns the event or throws.
     */
    public function constructEvent(string $payload, string $sigHeader): object
    {
        if (empty($this->webhookSecret)) {
            throw new HttpException(500, 'Stripe webhook secret not configured');
        }

        return \Stripe\Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);
    }
}
