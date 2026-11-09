<?php

namespace App\Services\Sell;

use App\Models\InventoryMovement;
use App\Models\Sell;
use App\Services\Inventory\InsufficientStockException;
use App\Services\Inventory\LocationStockService;
use App\Services\Inventory\StockReservationService;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Order state changes that move stock: cancellation, payment success/failure and expiry of the stock hold.
 *
 * Every method locks the order row (SELECT ... FOR UPDATE) and re-reads its state before acting, so duplicate
 * gateway callbacks, browser + IPN double notifications, staff double clicks and the expiry job racing a
 * payment all serialise on the order and each stock change happens exactly once (`stock_deducted` is the
 * guard). Lock order is always: order row -> products (ascending id) -> inventory rows, matching checkout.
 */
class OrderLifecycleService
{
    /** payment_status values that mean the buyer has paid what the order asked for online. */
    public const PAID_STATUSES = ['paid', 'shipping_deposit_paid'];

    public const REASON_PAYMENT_FAILED = 'Payment failed';
    public const REASON_PAYMENT_CANCELLED = 'Payment cancelled by customer';
    public const REASON_HOLD_EXPIRED = 'Payment not completed in time';
    public const REASON_GATEWAY_START_FAILED = 'Payment could not be started';

    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly LocationStockService $locStock,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Cancel an order and put its stock back once. Returns false when it was already cancelled.
     *
     * @param array $extra extra sells columns to write with the cancellation
     */
    public function cancel(int $sellId, string $reason, array $extra = [], bool $notify = true): bool
    {
        $cancelled = DB::transaction(function () use ($sellId, $reason, $extra) {
            $sell = Sell::whereKey($sellId)->lockForUpdate()->first();
            if (!$sell || $sell->status === 'Cancelled') {
                return null;
            }
            $old = $sell->status;
            $this->cancelLocked($sell, $reason, $extra);

            return [$sell, $old];
        }, 3);

        if (!$cancelled) {
            return false;
        }
        [$sell, $old] = $cancelled;
        if ($notify) {
            $this->notifications->notifyOrderStatusChanged((int) $sell->company_id, $sell->invoice_no, $old, 'Cancelled');
        }

        return true;
    }

    /**
     * The gateway confirmed the payment. Idempotent: a repeated success callback/IPN/webhook changes nothing.
     * If the stock hold had already been released (expired or failed first), the stock is taken again when it is
     * still there; otherwise the order stays cancelled, is flagged for a refund and staff are notified.
     *
     * @return bool true when the order is paid and holds its stock, false when it is paid but needs a refund
     */
    public function markPaid(int $sellId, ?string $transactionId, string $paidStatus = 'paid', string $transactionColumn = 'payment_transaction_id'): bool
    {
        $result = DB::transaction(function () use ($sellId, $transactionId, $paidStatus, $transactionColumn) {
            $sell = Sell::whereKey($sellId)->lockForUpdate()->first();
            if (!$sell) {
                return ['ok' => false, 'sell' => null, 'refund' => false];
            }
            if (in_array($sell->payment_status, self::PAID_STATUSES, true)) {
                // Duplicate confirmation: already recorded, stock already handled
                return ['ok' => $sell->status !== 'Cancelled', 'sell' => $sell, 'refund' => false];
            }

            $updates = [
                'payment_status' => $paidStatus,
                $transactionColumn => $transactionId,
                'stock_hold_expires_at' => null,
            ];
            $refund = false;
            if ($sell->status === 'Cancelled' && !$sell->stock_deducted) {
                if ($this->retakeStock($sell)) {
                    $updates += ['status' => 'Pending', 'cancel_reason' => null, 'stock_deducted' => true];
                } else {
                    $refund = true;
                    $updates['cancel_reason'] = 'Paid after the stock hold was released and the stock is gone. Refund the customer.';
                }
            }
            $sell->update($updates);

            return ['ok' => !$refund && $sell->status !== 'Cancelled', 'sell' => $sell, 'refund' => $refund];
        }, 3);

        if ($result['refund'] && $result['sell']) {
            $this->notifications->notifyPaidOrderNeedsRefund((int) $result['sell']->company_id, $result['sell']->invoice_no);
        }

        return $result['ok'];
    }

    /**
     * The gateway reported a failed or cancelled payment: cancel the order and release its stock, unless the order
     * is already paid (a late failure never overrides a success) or staff already moved it past Pending.
     *
     * @param string $paymentStatus 'failed' or 'cancelled'
     */
    public function markPaymentFailed(int $sellId, string $paymentStatus, string $reason): void
    {
        $cancelled = DB::transaction(function () use ($sellId, $paymentStatus, $reason) {
            $sell = Sell::whereKey($sellId)->lockForUpdate()->first();
            if (!$sell || in_array($sell->payment_status, self::PAID_STATUSES, true)) {
                return null;
            }
            if ($sell->status !== 'Pending') {
                // Already cancelled (e.g. expired) or being fulfilled: record the payment result only
                if ($sell->payment_status !== $paymentStatus) {
                    $sell->update(['payment_status' => $paymentStatus]);
                }

                return null;
            }
            $this->cancelLocked($sell, $reason, ['payment_status' => $paymentStatus]);

            return $sell;
        }, 3);

        if ($cancelled) {
            $this->notifications->notifyOrderStatusChanged((int) $cancelled->company_id, $cancelled->invoice_no, 'Pending', 'Cancelled');
        }
    }

    /**
     * Cancel online-gateway orders whose stock hold expired unpaid. Each order is re-checked under its row lock,
     * so a payment that lands at the same moment wins or loses cleanly. Returns the number released.
     */
    public function releaseExpiredHolds(int $limit = 200): int
    {
        $ids = Sell::where('payment_status', 'pending_payment')
            ->where('status', '<>', 'Cancelled')
            ->whereNotNull('stock_hold_expires_at')
            ->where('stock_hold_expires_at', '<=', now())
            ->orderBy('stock_hold_expires_at')
            ->limit($limit)
            ->pluck('id');

        $released = 0;
        foreach ($ids as $id) {
            $sell = DB::transaction(function () use ($id) {
                $sell = Sell::whereKey($id)->lockForUpdate()->first();
                if (!$sell || $sell->status === 'Cancelled' || $sell->payment_status !== 'pending_payment'
                    || !$sell->stock_hold_expires_at || $sell->stock_hold_expires_at->isFuture()) {
                    return null;
                }
                $this->cancelLocked($sell, self::REASON_HOLD_EXPIRED, ['payment_status' => 'expired']);

                return $sell;
            }, 3);
            if ($sell) {
                $released++;
                $this->notifications->notifyOrderStatusChanged((int) $sell->company_id, $sell->invoice_no, 'Pending', 'Cancelled');
            }
        }

        return $released;
    }

    /** Cancel an order whose row is locked by the caller's transaction. */
    private function cancelLocked(Sell $sell, string $reason, array $extra = []): void
    {
        if ($sell->stock_deducted) {
            $this->lockOrderProducts($sell);
            app(SellService::class)->restoreStock($sell);
        }
        $sell->update(array_merge([
            'status' => 'Cancelled',
            'cancel_reason' => mb_substr($reason, 0, 255),
            'stock_deducted' => false,
            'stock_hold_expires_at' => null,
            'due_amount' => 0,
            'fulfillment_status' => 'unfulfilled',
        ], $extra));
    }

    /**
     * Take the stock of a released order again (payment arrived after the hold ended), from the same locations
     * it drew from originally. Runs in a savepoint so a shortage leaves nothing half-taken.
     */
    private function retakeStock(Sell $sell): bool
    {
        $companyId = (int) $sell->company_id;
        $lines = StockReservationService::mergeLines($sell->items()->get(['product_id', 'variant_id', 'quantity'])->toArray());
        if (!$lines) {
            return true;
        }
        $locations = InventoryMovement::where('company_id', $companyId)
            ->where('reference_type', 'sell')->where('reference_id', $sell->id)
            ->where('quantity', '<', 0)->whereNotNull('location_id')
            ->distinct()->pluck('location_id')->map(fn ($id) => (int) $id)->all();
        if (!$locations) {
            $locations = $this->locStock->storefrontLocationIds($companyId);
        }

        try {
            DB::transaction(function () use ($companyId, $sell, $lines, $locations) {
                $this->reservations->lockAndValidate($companyId, $lines, $locations ?: null);
                $this->reservations->deduct($companyId, $sell, $lines, $locations ?: null, 'Paid after hold released');
            });
        } catch (InsufficientStockException|HttpException) {
            return false;
        }

        return true;
    }

    private function lockOrderProducts(Sell $sell): void
    {
        $this->reservations->lockProducts((int) $sell->company_id, $sell->items()->pluck('product_id')->all());
    }
}
