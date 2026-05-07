<?php

namespace App\Http\Controllers\Api\Gateway;

use App\Http\Controllers\Controller;
use App\Models\Sell;
use App\Services\Gateway\SSLCommerzService;
use App\Services\Gateway\PortWalletService;
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
                $order->update([
                    'payment_status'         => 'paid',
                    'payment_transaction_id' => $request->input('tran_id'),
                ]);
                return redirect("{$this->frontendUrl}/order/result?status=success&invoice={$order->invoice_no}");
            }
        } catch (\Throwable $e) {
            // fall through to fail redirect
        }

        return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
    }

    // POST /api/gateway/sslcommerz/fail
    public function sslFail(Request $request)
    {
        $order = $this->findOrderByTranId($request->input('tran_id'));
        $invoice = $order?->invoice_no ?? '';

        if ($order) {
            $order->update(['payment_status' => 'failed']);
        }

        return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$invoice}");
    }

    // POST /api/gateway/sslcommerz/cancel
    public function sslCancel(Request $request)
    {
        $order   = $this->findOrderByTranId($request->input('tran_id'));
        $invoice = $order?->invoice_no ?? '';

        if ($order) {
            $order->update(['payment_status' => 'cancelled']);
        }

        return redirect("{$this->frontendUrl}/order/result?status=cancel&invoice={$invoice}");
    }

    // POST /api/gateway/sslcommerz/ipn  (server-to-server — no redirect)
    public function sslIpn(Request $request)
    {
        $order = $this->findOrderByTranId($request->input('tran_id'));

        if (!$order || $order->payment_status === 'paid') {
            return response()->json(['status' => 'ok']);
        }

        try {
            $service = new SSLCommerzService($order->company_id);
            $valid   = $service->validatePayment(
                $request->input('val_id'),
                (float) $order->amount
            );

            if ($valid) {
                $order->update([
                    'payment_status'         => 'paid',
                    'payment_transaction_id' => $request->input('tran_id'),
                ]);
            }
        } catch (\Throwable $e) {
            // log but don't fail — IPN must return 200
        }

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
            $valid   = $service->verifyCallback($request->all());

            if ($valid && $request->input('status') === 'SUCCESSFUL') {
                $order->update([
                    'payment_status'         => 'paid',
                    'payment_transaction_id' => $request->input('portwallet_txn_id'),
                ]);
                return redirect("{$this->frontendUrl}/order/result?status=success&invoice={$order->invoice_no}");
            }
        } catch (\Throwable $e) {
            // fall through
        }

        $order->update(['payment_status' => 'failed']);
        return redirect("{$this->frontendUrl}/order/result?status=fail&invoice={$order->invoice_no}");
    }

    private function findOrderByTranId(string $tranId): ?Sell
    {
        // tran_id format: "INV-{invoice_no}" — set in StorefrontOrderController
        $invoice = str_replace('INV-', '', $tranId);
        return Sell::where('invoice_no', $invoice)->first();
    }
}
