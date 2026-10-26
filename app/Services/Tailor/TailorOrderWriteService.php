<?php

namespace App\Services\Tailor;

use App\Models\TailorAssignment;
use App\Models\TailorCustomer;
use App\Models\TailorDorji;
use App\Models\TailorFabric;
use App\Models\TailorOrder;
use App\Models\TailorOrderItem;
use App\Models\TailorPayment;
use App\Models\TailorStatusLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Write side of the "Tailor orders" / "New tailor order" screens.
 */
class TailorOrderWriteService
{
    public function __construct(private readonly \App\Services\Notification\NotificationService $notifications)
    {
    }

    /** Stage ids accepted by transition(); "new" maps to the stored "pending". */
    public const STATUSES = ['new', 'cutting', 'stitching', 'trial', 'ready', 'delivered', 'cancelled'];

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    // ─── Create ──────────────────────────────────────────────────────────────

    public function place(int $companyId, array $data, int $userId): TailorOrder
    {
        $today = today()->toDateString();
        $orderDate = $data['order_date'];
        $delivery  = $data['delivery_date'];
        if ($orderDate > $today)    $this->fail('order_date', 'Order date cannot be in the future.');
        if ($delivery < $orderDate) $this->fail('delivery_date', 'Delivery date cannot be before the order date.');
        if ($delivery < $today)     $this->fail('delivery_date', 'Delivery date cannot be in the past.');

        return DB::transaction(function () use ($companyId, $data, $userId, $orderDate, $delivery) {
            // Customer: existing one, or find-or-create by phone.
            if (!empty($data['customer_id'])) {
                $customer = TailorCustomer::where('company_id', $companyId)->find($data['customer_id']);
                if (!$customer) $this->fail('customer_id', 'Customer not found.');
            } else {
                if (empty($data['customer_name']) || empty($data['customer_phone'])) {
                    $this->fail('customer_name', 'Customer name and phone are required.');
                }
                $customer = TailorCustomer::updateOrCreate(
                    ['company_id' => $companyId, 'phone' => $data['customer_phone']],
                    ['name' => $data['customer_name'], 'address' => $data['customer_address'] ?? null]
                );
            }

            $dorji = null;
            if (!empty($data['dorji_id'])) {
                $dorji = TailorDorji::where('company_id', $companyId)->find($data['dorji_id']);
                if (!$dorji) $this->fail('dorji_id', 'Dorji not found.');
            }

            // Build + price the lines.
            $lines = [];
            $stitchTotal = 0.0;
            $fabricTotal = 0.0;
            foreach ($data['items'] as $i => $it) {
                $n   = $i + 1;
                $qty = max(1, (int) ($it['quantity'] ?? 1));
                $rate = (float) ($it['stitching_price'] ?? 0);
                $own  = ($it['fabric_source'] ?? 'own') === 'own';
                $fabric = null;
                $fq = 0.0; $fp = 0.0;
                if (!$own) {
                    $fabric = !empty($it['fabric_id'])
                        ? TailorFabric::where('company_id', $companyId)->find($it['fabric_id']) : null;
                    if (!$fabric) $this->fail("items.$i.fabric_id", "Item $n: choose a fabric or switch to the customer's fabric.");
                    $fq = (float) ($it['fabric_quantity'] ?? 0);
                    if ($fq <= 0) $this->fail("items.$i.fabric_quantity", "Item $n: enter the fabric quantity.");
                    $fp = isset($it['fabric_unit_price']) ? (float) $it['fabric_unit_price'] : (float) $fabric->selling_price;
                }
                if (!empty($it['measurement_id'])) {
                    $ok = DB::table('tailor_measurements')->where('company_id', $companyId)
                        ->where('customer_id', $customer->id)->where('id', $it['measurement_id'])->exists();
                    if (!$ok) $this->fail("items.$i.measurement_id", "Item $n: measurement does not belong to this customer.");
                }
                $stitchTotal += $qty * $rate;
                $fabricTotal += round($fq * $fp, 2);
                $lines[] = [
                    'product_type' => $it['product_type'], 'quantity' => $qty, 'stitching_price' => $rate,
                    'fabric_source' => $own ? 'own' : 'shop', 'own_fabric_note' => $own ? ($it['own_fabric_note'] ?? null) : null,
                    'fabric_id' => $fabric?->id, 'fabric_quantity' => $fq, 'fabric_unit_price' => $fp,
                    'measurement_id' => $it['measurement_id'] ?? null,
                    'style_options' => array_values($it['style_options'] ?? []) ?: null,
                    'notes' => $it['notes'] ?? null,
                ];
            }

            $urgent = (bool) ($data['is_urgent'] ?? false);
            $urgFee = $urgent ? TailorOrderQueryService::URGENT_FEE : 0;
            $extra  = (float) ($data['extra_charge'] ?? 0);
            $subtotal = $stitchTotal + $fabricTotal + $urgFee + $extra;
            $discount = min((float) ($data['discount'] ?? 0), $subtotal);
            $total    = round(max(0, $subtotal - $discount), 2);
            if ($total <= 0) $this->fail('items', 'Order total must be greater than 0.');
            $advance = round((float) ($data['advance_payment'] ?? 0), 2);
            if ($advance > $total + 0.005) $this->fail('advance_payment', 'Advance cannot be more than the total.');

            $order = TailorOrder::create([
                'company_id' => $companyId, 'customer_id' => $customer->id,
                'order_date' => $orderDate, 'delivery_date' => $delivery,
                'stitching_charge' => round($stitchTotal, 2), 'extra_charge' => $extra, 'discount' => $discount,
                'is_urgent' => $urgent, 'urgent_fee' => $urgFee,
                'notes' => $data['notes'] ?? null,
                'order_status' => $dorji ? 'assigned' : 'pending',
            ]);

            $created = [];
            foreach ($lines as $line) {
                $created[] = TailorOrderItem::create(array_merge($line, ['order_id' => $order->id]));
            }

            if ($advance > 0) {
                $this->insertPayment($companyId, $order->id, $advance, $data['advance_payment_method'] ?? 'cash',
                    $orderDate, null, 'Advance payment', 'advance', $userId);
            }

            $order->load('items');
            $order->paid_amount = $advance;
            $order->recalculate();
            $order->save();

            if ($dorji) {
                // One assignment per line, paid at the dorji's per-piece rate (fixed commission);
                // percentage-commission dorjis fall back to a share of the stitching price.
                foreach ($created as $item) {
                    $pieces = max(1, (int) $item->quantity);
                    $rate = $dorji->commission_type === 'percentage'
                        ? round((float) $item->stitching_price * (float) $dorji->commission_value / 100, 2)
                        : (float) $dorji->commission_value;
                    TailorAssignment::create([
                        'company_id' => $companyId, 'order_id' => $order->id, 'dorji_id' => $dorji->id,
                        'assigned_date' => today()->toDateString(), 'expected_completion' => $delivery,
                        'task' => $item->product_type, 'pieces' => $pieces, 'rate_per_piece' => $rate,
                        'dorji_charge' => round($pieces * $rate, 2), 'work_status' => 'assigned',
                        'admin_notes' => $item->product_type . ' ×' . $pieces,
                    ]);
                }
                $order->update(['dorji_id' => $dorji->id]);
            }

            TailorStatusLog::create([
                'order_id' => $order->id, 'from_status' => null, 'to_status' => $order->order_status,
                'changed_by' => $userId ?: null, 'note' => 'Order created',
            ]);

            return $order->load(['customer', 'items.fabric', 'assignments.dorji', 'payments', 'statusLogs']);
        });
    }

    // ─── Status ──────────────────────────────────────────────────────────────

    public function transition(int $companyId, int $id, string $to, ?string $note, bool $collectDue, int $userId): TailorOrder
    {
        $order = DB::transaction(function () use ($companyId, $id, $to, $note, $collectDue, $userId) {
            $order = TailorOrder::with('items')->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $stored = $to === 'new' ? 'pending' : $to;
            $from = $order->order_status;
            if (TailorOrderQueryService::stageOf($from) === TailorOrderQueryService::stageOf($stored) && $from === $stored) {
                $this->fail('status', 'Order is already in that status.');
            }
            // "Received" while a dorji is already assigned keeps the assigned marker.
            if ($to === 'new' && $order->assignments()->exists()) $stored = 'assigned';

            $note = $note !== null ? trim($note) : null;

            if ($stored === 'delivered') {
                // Delivering always collects the remaining balance in cash.
                $due = max(0.0, round((float) $order->total_amount - (float) $order->paid_amount, 2));
                if ($due > 0) {
                    $this->insertPayment($companyId, $order->id, $due, 'cash', today()->toDateString(), null, 'Final payment', 'final', $userId);
                    $order->paid_amount = round((float) $order->paid_amount + $due, 2);
                    $order->recalculate();
                    $note = $note ?: 'Final payment collected';
                }
            }

            // Fabric leaves stock the first time the order moves past "Received" (cutting).
            if ($stored !== 'cancelled' && TailorOrderQueryService::stageOf($stored) !== 'new' && !$order->fabric_deducted) {
                $this->deductFabric($order);
            }

            if ($stored === 'cancelled') {
                $this->applyCancellation($order);
                $note = $note ?: 'Order cancelled';
            }

            $order->order_status = $stored;
            $order->save();

            TailorStatusLog::create([
                'order_id' => $order->id, 'from_status' => $from, 'to_status' => $stored,
                'changed_by' => $userId ?: null, 'note' => $note ?: null,
            ]);

            return $order->fresh(['customer', 'items.fabric', 'assignments.dorji', 'payments', 'statusLogs']);
        });

        if ($to === 'ready') {
            $this->notifications->notifyTailorOrderReady($companyId, (string) $order->order_number, (string) ($order->customer?->name ?? 'customer'));
        }

        return $order;
    }

    // ─── Fabric stock ────────────────────────────────────────────────────────

    /** Per-fabric totals needed by the order's shop-fabric lines. */
    private function fabricNeeds(TailorOrder $order): array
    {
        $need = [];
        foreach ($order->items as $it) {
            if ($it->fabric_id && (float) $it->fabric_quantity > 0) {
                $need[$it->fabric_id] = ($need[$it->fabric_id] ?? 0) + (float) $it->fabric_quantity;
            }
        }
        return $need;
    }

    private function deductFabric(TailorOrder $order): void
    {
        $need = $this->fabricNeeds($order);
        $fabrics = [];
        foreach ($need as $fid => $qty) {
            $f = TailorFabric::where('company_id', $order->company_id)->lockForUpdate()->find($fid);
            if (!$f) $this->fail('status', 'Fabric #' . $fid . ' no longer exists.');
            if ((float) $f->stock_quantity + 0.0001 < $qty) {
                $this->fail('status', 'Not enough ' . $f->name . ': need ' . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.')
                    . ', only ' . rtrim(rtrim(number_format((float) $f->stock_quantity, 2, '.', ''), '0'), '.') . ' in stock');
            }
            $fabrics[$fid] = $f;
        }
        foreach ($need as $fid => $qty) {
            $f = $fabrics[$fid];
            $f->skipMovementLog = true;
            $f->stock_quantity = round((float) $f->stock_quantity - $qty, 2);
            $f->save();
            \App\Models\TailorFabricMovement::log($f, 'order_use', -$qty, 'Order ' . $order->order_number, [
                'reference_type' => 'tailor_order', 'reference_id' => $order->id,
            ]);
        }
        $order->fabric_deducted = true;
    }

    /**
     * Shared cancel side-effects (used by transition() and the legacy status endpoint):
     * puts deducted fabric back, clears fabric_deducted and removes unstarted assignments.
     * Caller saves the order.
     */
    public function applyCancellation(TailorOrder $order): void
    {
        if (!$order->relationLoaded('items')) $order->load('items');
        $this->restoreFabric($order);
        TailorAssignment::where('company_id', $order->company_id)->where('order_id', $order->id)
            ->where('work_status', 'assigned')->delete();
    }

    private function restoreFabric(TailorOrder $order): void
    {
        if (!$order->fabric_deducted) return;
        foreach ($this->fabricNeeds($order) as $fid => $qty) {
            $f = TailorFabric::where('company_id', $order->company_id)->lockForUpdate()->find($fid);
            if (!$f) continue;
            $f->skipMovementLog = true;
            $f->stock_quantity = round((float) $f->stock_quantity + $qty, 2);
            $f->save();
            \App\Models\TailorFabricMovement::log($f, 'adjustment', $qty, 'Order ' . $order->order_number . ' cancelled', [
                'reference_type' => 'tailor_order', 'reference_id' => $order->id,
            ]);
        }
        $order->fabric_deducted = false;
    }

    // ─── Payments ────────────────────────────────────────────────────────────

    public function recordPayment(int $companyId, int $id, array $data, int $userId): TailorPayment
    {
        return DB::transaction(function () use ($companyId, $id, $data, $userId) {
            $order = TailorOrder::with('items')->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ($order->order_status === 'cancelled') $this->fail('amount', 'Cannot record a payment on a cancelled order.');

            $due = max(0.0, round((float) $order->total_amount - (float) $order->paid_amount, 2));
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0)            $this->fail('amount', 'Enter an amount greater than 0.');
            if ($amount > $due + 0.005)  $this->fail('amount', 'Amount is more than the balance (' . number_format($due, 2) . ').');
            if ($data['payment_date'] > today()->toDateString()) $this->fail('payment_date', 'Payment date cannot be in the future.');

            $payment = $this->insertPayment($companyId, $order->id, $amount, $data['payment_method'] ?? 'cash',
                $data['payment_date'], $data['reference'] ?? null, $data['notes'] ?? null,
                'payment', $userId);

            // Same rule as TailorService::createPayment: paid = sum of customer payments.
            $order->paid_amount = (float) TailorPayment::where('order_id', $order->id)
                ->where(fn ($w) => $w->whereNull('kind')->orWhere('kind', 'customer'))->sum('amount');
            $order->recalculate();
            $order->save();

            return $payment->load('order.customer');
        });
    }

    private function insertPayment(int $companyId, int $orderId, float $amount, string $method, string $date,
                                   ?string $reference, ?string $notes, string $type, int $userId): TailorPayment
    {
        return TailorPayment::create([
            'company_id' => $companyId, 'order_id' => $orderId, 'kind' => 'customer',
            'amount' => $amount, 'payment_method' => strtolower($method), 'payment_type' => $type,
            'payment_date' => $date, 'reference' => $reference ?: null, 'notes' => $notes ?: null,
            'created_by' => $userId ?: null,
        ]);
    }
}
