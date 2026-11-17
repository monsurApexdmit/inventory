<?php

namespace App\Http\Controllers\Api\Gateway;

use App\Http\Controllers\Controller;
use App\Models\Sell;
use App\Services\Gateway\SSLCommerzService;
use App\Services\Gateway\PortWalletService;
use App\Services\Gateway\StripeService;
use App\Services\Gateway\PayPalService;
use App\Services\Gateway\BkashService;
use App\Services\Gateway\NagadService;
use App\Services\Sell\OrderLifecycleService;
use Illuminate\Http\Request;

class GatewayController extends Controller
{
    private string $frontendUrl;

    public function __construct()
    {
        $this->frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:8080'), '/');
    }

    // POST /api/gateway/sslcommerz/success
    public function sslSuccess(Request $request)
    {
        $order = $this->findOrderByTranId($request->input('tran_id'));

        if (!$order) {
            return redirect("{$this->frontendUrl}/order/result?status=fail&reason=order_not_found");
        }

        try {
            $service = new SSLCommerzService($order->company_id);
            $valid   = $service->validatePayment(
                $request->input('val_id'),
                (float) $order->amount
            );

            if ($valid) {
                $ok = $this->paid($order, $request->input('tran_id'));
                return redirect("{$this->frontendUrl}/order/result?status={$this->outcome($ok)}&invoice={$order->invoice_no}");
            }
        } catch (\Throwable $e) {}

        return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
    }

    // POST /api/gateway/sslcommerz/fail
    public function sslFail(Request $request)
    {
        $order = $this->findOrderByTranId($request->input('tran_id'));
        $invoice = $order?->invoice_no ?? '';
        if ($order) $this->failed($order);
        return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$invoice}");
    }

    // POST /api/gateway/sslcommerz/cancel
    public function sslCancel(Request $request)
    {
        $order   = $this->findOrderByTranId($request->input('tran_id'));
        $invoice = $order?->invoice_no ?? '';
        if ($order) $this->cancelled($order);
        return redirect("{$this->frontendUrl}/order/result?status=cancel&invoice={$invoice}");
    }

    // POST /api/gateway/sslcommerz/ipn
    public function sslIpn(Request $request)
    {
        $order = $this->findOrderByTranId($request->input('tran_id'));
        if (!$order || $order->payment_status === 'paid') {
            return response()->json(['status' => 'ok']);
        }

        try {
            $service = new SSLCommerzService($order->company_id);
            if ($service->validatePayment($request->input('val_id'), (float) $order->amount)) {
                $this->paid($order, $request->input('tran_id'));
            }
        } catch (\Throwable $e) {}

        return response()->json(['status' => 'ok']);
    }

    // POST /api/gateway/portwallet/callback
    public function portwalletCallback(Request $request)
    {
        $invoice = $request->input('invoice');
        $order   = Sell::where('invoice_no', $invoice)->first();

        if (!$order) {
            return redirect("{$this->frontendUrl}/order/result?status=fail&reason=order_not_found");
        }

        try {
            $service = new PortWalletService($order->company_id);
            if ($service->verifyCallback($request->all()) && $request->input('status') === 'SUCCESSFUL') {
                $ok = $this->paid($order, $request->input('portwallet_txn_id'));
                return redirect("{$this->frontendUrl}/order/result?status={$this->outcome($ok)}&invoice={$order->invoice_no}");
            }
        } catch (\Throwable $e) {}

        $this->failed($order);
        return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
    }

    // GET /api/gateway/stripe/success?session_id=xxx&tran_id=xxx
    public function stripeSuccess(Request $request)
    {
        $sessionId = $request->input('session_id');
        $order     = $this->findOrderByTranId($request->input('tran_id', ''));

        if (!$order || !$sessionId) {
            return redirect("{$this->frontendUrl}/order/result?status=fail&reason=order_not_found");
        }

        try {
            $service = new StripeService($order->company_id);
            $session = $service->getSession($sessionId);

            if ($session->payment_status === 'paid') {
                $ok = $this->paid($order, $session->payment_intent);
                return redirect("{$this->frontendUrl}/order/result?status={$this->outcome($ok)}&invoice={$order->invoice_no}");
            }
        } catch (\Throwable $e) {}

        return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
    }

    // GET /api/gateway/stripe/cancel?tran_id=xxx
    public function stripeCancel(Request $request)
    {
        $order = $this->findOrderByTranId($request->input('tran_id', ''));
        if ($order) $this->cancelled($order);
        return redirect("{$this->frontendUrl}/order/result?status=cancel&invoice=" . ($order?->invoice_no ?? ''));
    }

    // POST /api/gateway/stripe/webhook  (Stripe server-to-server)
    public function stripeWebhook(Request $request)
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature', '');

        // Find any paid order to get company_id — try first active company as fallback
        // In production, use account metadata or a dedicated webhook per company
        $order = null;
        try {
            // Try to get company_id from first company that has stripe configured
            // A real multi-tenant setup would use Stripe Connect or per-company webhook endpoints
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, env('STRIPE_WEBHOOK_SECRET', ''));

            if ($event->type === 'checkout.session.completed') {
                $session  = $event->data->object;
                $tranId   = $session->metadata->tran_id ?? '';
                $order    = $this->findOrderByTranId($tranId);

                if ($order && $session->payment_status === 'paid') {
                    $this->paid($order, $session->payment_intent);
                }
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        return response()->json(['status' => 'ok']);
    }

    // GET /api/gateway/paypal/success?token=xxx&PayerID=xxx&tran_id=xxx
    public function paypalSuccess(Request $request)
    {
        $orderId = $request->input('token'); // PayPal calls it "token"
        $order   = $this->findOrderByTranId($request->input('tran_id', ''));

        if (!$order || !$orderId) {
            return redirect("{$this->frontendUrl}/order/result?status=fail&reason=order_not_found");
        }

        try {
            $service  = new PayPalService($order->company_id);
            $captured = $service->captureOrder($orderId);

            if (($captured['status'] ?? '') === 'COMPLETED') {
                $txnId = $captured['purchase_units'][0]['payments']['captures'][0]['id'] ?? $orderId;
                $ok = $this->paid($order, $txnId);
                return redirect("{$this->frontendUrl}/order/result?status={$this->outcome($ok)}&invoice={$order->invoice_no}");
            }
        } catch (\Throwable $e) {}

        return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
    }

    // GET /api/gateway/paypal/cancel?tran_id=xxx
    public function paypalCancel(Request $request)
    {
        $order = $this->findOrderByTranId($request->input('tran_id', ''));
        if ($order) $this->cancelled($order);
        return redirect("{$this->frontendUrl}/order/result?status=cancel&invoice=" . ($order?->invoice_no ?? ''));
    }

    // ─── bKash Callback ──────────────────────────────────────────────────────

    // GET /api/gateway/bkash/callback?paymentID=xxx&status=success&tran_id=INV-xxx
    public function bkashCallback(Request $request)
    {
        $status    = $request->input('status');
        $paymentId = $request->input('paymentID');
        $order     = $this->findOrderByTranId($request->input('tran_id', ''));

        if (!$order) {
            return redirect("{$this->frontendUrl}/order/result?status=fail&reason=order_not_found");
        }

        if ($status === 'cancel') {
            $this->cancelled($order);
            return redirect("{$this->frontendUrl}/order/result?status=cancel&invoice={$order->invoice_no}");
        }

        if ($status !== 'success' || !$paymentId) {
            $this->failed($order);
            return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
        }

        try {
            $service = new BkashService($order->company_id);
            $trxId   = $service->executePayment($paymentId);
            $ok = $this->paid($order, $trxId);
            return redirect("{$this->frontendUrl}/order/result?status={$this->outcome($ok)}&invoice={$order->invoice_no}");
        } catch (\Throwable $e) {
            $this->failed($order);
            return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
        }
    }

    // ─── Nagad Callback ──────────────────────────────────────────────────────

    // GET /api/gateway/nagad/callback?payment_ref_id=xxx&order_id=INV-xxx&status=Success
    public function nagadCallback(Request $request)
    {
        $status    = $request->input('status');
        $refId     = $request->input('payment_ref_id');
        $orderId   = $request->input('order_id', '');
        $order     = $this->findOrderByTranId($orderId);

        if (!$order) {
            return redirect("{$this->frontendUrl}/order/result?status=fail&reason=order_not_found");
        }

        if ($status !== 'Success' || !$refId) {
            $this->failed($order);
            return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
        }

        try {
            $service = new NagadService($order->company_id);
            $txnId   = $service->verifyPayment($refId);
            $ok = $this->paid($order, $txnId);
            return redirect("{$this->frontendUrl}/order/result?status={$this->outcome($ok)}&invoice={$order->invoice_no}");
        } catch (\Throwable $e) {
            $this->failed($order);
            return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
        }
    }

    // ─── COD Shipping Deposit Callbacks ──────────────────────────────────────

    // POST /api/gateway/cod-deposit/sslcommerz/success
    public function codDepositSslSuccess(Request $request)
    {
        $order = $this->findOrderByTranId($request->input('tran_id'));
        if (!$order) {
            return redirect("{$this->frontendUrl}/order/result?status=fail&reason=order_not_found");
        }

        try {
            $service = new SSLCommerzService($order->company_id);
            $valid   = $service->validatePayment(
                $request->input('val_id'),
                (float) $order->shipping_deposit_amount
            );

            if ($valid) {
                $ok = $this->depositPaid($order, $request->input('tran_id'));
                return redirect("{$this->frontendUrl}/order/result?status={$this->outcome($ok)}&invoice={$order->invoice_no}&deposit=1");
            }
        } catch (\Throwable $e) {}

        $this->failed($order);
        return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
    }

    // POST /api/gateway/cod-deposit/sslcommerz/fail
    public function codDepositSslFail(Request $request)
    {
        $order = $this->findOrderByTranId($request->input('tran_id'));
        if ($order) $this->failed($order);
        return redirect("{$this->frontendUrl}/order/result?status=fail&invoice=" . ($order?->invoice_no ?? ''));
    }

    // POST /api/gateway/cod-deposit/sslcommerz/cancel
    public function codDepositSslCancel(Request $request)
    {
        $order = $this->findOrderByTranId($request->input('tran_id'));
        if ($order) $this->cancelled($order);
        return redirect("{$this->frontendUrl}/order/result?status=cancel&invoice=" . ($order?->invoice_no ?? ''));
    }

    // POST /api/gateway/cod-deposit/sslcommerz/ipn
    public function codDepositSslIpn(Request $request)
    {
        $order = $this->findOrderByTranId($request->input('tran_id'));
        if (!$order || $order->payment_status === 'shipping_deposit_paid') {
            return response()->json(['status' => 'ok']);
        }

        try {
            $service = new SSLCommerzService($order->company_id);
            if ($service->validatePayment($request->input('val_id'), (float) $order->shipping_deposit_amount)) {
                $this->depositPaid($order, $request->input('tran_id'));
            }
        } catch (\Throwable $e) {}

        return response()->json(['status' => 'ok']);
    }

    // POST /api/gateway/cod-deposit/portwallet/callback
    public function codDepositPortwalletCallback(Request $request)
    {
        $invoice = $request->input('invoice');
        $order   = Sell::where('invoice_no', $invoice)->first();

        if (!$order) {
            return redirect("{$this->frontendUrl}/order/result?status=fail&reason=order_not_found");
        }

        try {
            $service = new PortWalletService($order->company_id);
            if ($service->verifyCallback($request->all()) && $request->input('status') === 'SUCCESSFUL') {
                $ok = $this->depositPaid($order, $request->input('portwallet_txn_id'));
                return redirect("{$this->frontendUrl}/order/result?status={$this->outcome($ok)}&invoice={$order->invoice_no}&deposit=1");
            }
        } catch (\Throwable $e) {}

        $this->failed($order);
        return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
    }

    // GET /api/gateway/cod-deposit/bkash/callback
    public function codDepositBkashCallback(Request $request)
    {
        $status    = $request->input('status');
        $paymentId = $request->input('paymentID');
        $order     = $this->findOrderByTranId($request->input('tran_id', ''));

        if (!$order) {
            return redirect("{$this->frontendUrl}/order/result?status=fail&reason=order_not_found");
        }

        if ($status === 'cancel') {
            $this->cancelled($order);
            return redirect("{$this->frontendUrl}/order/result?status=cancel&invoice={$order->invoice_no}");
        }

        if ($status !== 'success' || !$paymentId) {
            $this->failed($order);
            return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
        }

        try {
            $service = new BkashService($order->company_id);
            $trxId   = $service->executePayment($paymentId);
            $ok = $this->depositPaid($order, $trxId);
            return redirect("{$this->frontendUrl}/order/result?status={$this->outcome($ok)}&invoice={$order->invoice_no}&deposit=1");
        } catch (\Throwable $e) {
            $this->failed($order);
            return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
        }
    }

    // GET /api/gateway/cod-deposit/nagad/callback
    public function codDepositNagadCallback(Request $request)
    {
        $status  = $request->input('status');
        $refId   = $request->input('payment_ref_id');
        $orderId = $request->input('order_id', '');
        $order   = $this->findOrderByTranId($orderId);

        if (!$order) {
            return redirect("{$this->frontendUrl}/order/result?status=fail&reason=order_not_found");
        }

        if ($status !== 'Success' || !$refId) {
            $this->failed($order);
            return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
        }

        try {
            $service = new NagadService($order->company_id);
            $txnId   = $service->verifyPayment($refId);
            $ok = $this->depositPaid($order, $txnId);
            return redirect("{$this->frontendUrl}/order/result?status={$this->outcome($ok)}&invoice={$order->invoice_no}&deposit=1");
        } catch (\Throwable $e) {
            $this->failed($order);
            return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
        }
    }

    // GET /api/gateway/cod-deposit/stripe/success?session_id=xxx&tran_id=xxx
    public function codDepositStripeSuccess(Request $request)
    {
        $sessionId = $request->input('session_id');
        $order     = $this->findOrderByTranId($request->input('tran_id', ''));

        if (!$order || !$sessionId) {
            return redirect("{$this->frontendUrl}/order/result?status=fail&reason=order_not_found");
        }

        try {
            $service = new StripeService($order->company_id);
            $session = $service->getSession($sessionId);

            if ($session->payment_status === 'paid') {
                $ok = $this->depositPaid($order, $session->payment_intent);
                return redirect("{$this->frontendUrl}/order/result?status={$this->outcome($ok)}&invoice={$order->invoice_no}&deposit=1");
            }
        } catch (\Throwable $e) {}

        $this->failed($order);
        return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
    }

    // GET /api/gateway/cod-deposit/stripe/cancel?tran_id=xxx
    public function codDepositStripeCancel(Request $request)
    {
        $order = $this->findOrderByTranId($request->input('tran_id', ''));
        if ($order) $this->cancelled($order);
        return redirect("{$this->frontendUrl}/order/result?status=cancel&invoice=" . ($order?->invoice_no ?? ''));
    }

    // GET /api/gateway/cod-deposit/paypal/success?token=xxx&tran_id=xxx
    public function codDepositPaypalSuccess(Request $request)
    {
        $orderId = $request->input('token');
        $order   = $this->findOrderByTranId($request->input('tran_id', ''));

        if (!$order || !$orderId) {
            return redirect("{$this->frontendUrl}/order/result?status=fail&reason=order_not_found");
        }

        try {
            $service  = new PayPalService($order->company_id);
            $captured = $service->captureOrder($orderId);

            if (($captured['status'] ?? '') === 'COMPLETED') {
                $txnId = $captured['purchase_units'][0]['payments']['captures'][0]['id'] ?? $orderId;
                $ok = $this->depositPaid($order, $txnId);
                return redirect("{$this->frontendUrl}/order/result?status={$this->outcome($ok)}&invoice={$order->invoice_no}&deposit=1");
            }
        } catch (\Throwable $e) {}

        $this->failed($order);
        return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
    }

    // GET /api/gateway/cod-deposit/paypal/cancel?tran_id=xxx
    public function codDepositPaypalCancel(Request $request)
    {
        $order = $this->findOrderByTranId($request->input('tran_id', ''));
        if ($order) $this->cancelled($order);
        return redirect("{$this->frontendUrl}/order/result?status=cancel&invoice=" . ($order?->invoice_no ?? ''));
    }

    // ─── Order state changes (order row locked, idempotent; see OrderLifecycleService) ─

    /** Full payment confirmed. False when it arrived after the stock was released and sold (refund needed). */
    private function paid(Sell $order, ?string $transactionId): bool
    {
        return $this->lifecycle()->markPaid((int) $order->id, $transactionId);
    }

    /** COD shipping deposit confirmed. */
    private function depositPaid(Sell $order, ?string $transactionId): bool
    {
        return $this->lifecycle()->markPaid((int) $order->id, $transactionId, 'shipping_deposit_paid', 'shipping_deposit_transaction_id');
    }

    /** Payment failed: the unpaid order is cancelled and its stock released (never overrides a payment). */
    private function failed(Sell $order): void
    {
        $this->lifecycle()->markPaymentFailed((int) $order->id, 'failed', OrderLifecycleService::REASON_PAYMENT_FAILED);
    }

    /** Buyer cancelled at the gateway: handled like a failure. */
    private function cancelled(Sell $order): void
    {
        $this->lifecycle()->markPaymentFailed((int) $order->id, 'cancelled', OrderLifecycleService::REASON_PAYMENT_CANCELLED);
    }

    private function outcome(bool $ok): string
    {
        return $ok ? 'success' : 'fail&reason=stock_released';
    }

    private function lifecycle(): OrderLifecycleService
    {
        return app(OrderLifecycleService::class);
    }

    private function findOrderByTranId(string $tranId): ?Sell
    {
        $invoice = str_replace('INV-', '', $tranId);
        return Sell::where('invoice_no', $invoice)->first();
    }
}
