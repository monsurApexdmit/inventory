<?php

namespace App\Services\CustomerReturn;

use App\DTOs\CustomerReturn\CustomerReturnDTO;
use App\DTOs\CustomerReturn\CustomerReturnMapper;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\VariantInventory;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Repositories\Contracts\ICustomerReturnRepository;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Models\Sell;
use App\Services\Inventory\LocationStockService;
use App\Services\Notification\NotificationService;
use App\Support\ActorName;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerReturnService
{
    private readonly CustomerReturnMapper $mapper;

    public function __construct(
        private readonly ICustomerReturnRepository $repository,
        private readonly NotificationService $notificationService,
        private readonly LocationStockService $stock,
    )
    {
        $this->mapper = new CustomerReturnMapper();
    }

    private const DONE = ['completed', 'approved'];

    /** Base query: company + search/reason/date filters (no tab). */
    private function filteredQuery(int $companyId, array $filters)
    {
        $q = CustomerReturn::where('company_id', $companyId);

        if (!empty($filters['customer_id'])) {
            $q->where('customer_id', (int) $filters['customer_id']);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('return_number', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }
        $reason = trim((string) ($filters['reason'] ?? ''));
        if ($reason !== '' && strtolower($reason) !== 'all') {
            $q->where(function ($w) use ($reason) {
                $w->where('reason', 'like', "%{$reason}%")
                    ->orWhereExists(function ($sub) use ($reason) {
                        $sub->select(DB::raw(1))->from('customer_return_items as i')
                            ->whereColumn('i.return_id', 'customer_returns.id')
                            ->where('i.reason', 'like', "%{$reason}%");
                    });
            });
        }
        if (!empty($filters['from'])) {
            $q->whereDate('created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q->whereDate('created_at', '<=', $filters['to']);
        }
        return $q;
    }

    private function applyTab($q, string $tab)
    {
        return match ($tab) {
            'pending' => $q->where('status', 'pending'),
            'completed', 'approved' => $q->whereIn('status', self::DONE),
            'rejected' => $q->where('status', 'rejected'),
            'restocked' => $q->whereIn('status', self::DONE)->where('restock', true),
            'written_off', 'written-off', 'written' => $q->whereIn('status', self::DONE)->where('restock', false),
            default => $q,
        };
    }

    public function list(int $companyId, array $filters): array
    {
        $tab = (string) ($filters['tab'] ?? $filters['status'] ?? 'all');
        $perPage = min(max((int) ($filters['per_page'] ?? $filters['limit'] ?? 10), 1), 500);

        $query = $this->applyTab($this->filteredQuery($companyId, $filters), $tab)
            ->with('items')
            ->orderByDesc('created_at')->orderByDesc('id');
        $paginated = $query->paginate($perPage);

        $locIds = collect($paginated->items())->pluck('location_id')->filter()->unique()->all();
        $locations = $locIds ? Location::whereIn('id', $locIds)->pluck('name', 'id')->all() : [];
        $rows = array_map(fn ($r) => $this->row($r, $locations), $paginated->items());

        $counts = [];
        foreach (['all', 'pending', 'completed', 'rejected', 'restocked', 'written_off'] as $t) {
            $counts[$t] = $this->applyTab($this->filteredQuery($companyId, $filters), $t)->count();
        }

        return [
            'data' => $rows,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'counts' => $counts,
            'stats' => $this->logStats($companyId),
        ];
    }

    private function row(CustomerReturn $r, array $locations): array
    {
        $r->loadMissing('items');
        $lines = $r->items->map(function ($i) {
            $name = (string) $i->product_name;
            if ($i->variant_name && $i->variant_name !== 'Default') {
                $name .= ' - ' . $i->variant_name;
            }
            return [
                'name' => $name,
                'quantity' => (int) $i->quantity,
                'price' => (float) $i->price,
                'total' => round((float) $i->price * (int) $i->quantity, 2),
                'reason' => $i->reason,
            ];
        })->values()->all();

        $status = in_array($r->status, self::DONE, true) ? 'completed' : $r->status;
        $reason = $r->reason ?: (collect($lines)->pluck('reason')->filter()->unique()->implode(', ') ?: null);

        return [
            'id' => $r->id,
            'returnNumber' => $r->return_number,
            'createdAt' => $r->created_at?->toIso8601String(),
            'customerId' => $r->customer_id,
            'customerName' => $r->customer_name,
            'orderId' => $r->order_id,
            'invoiceNo' => $r->order_number,
            'itemsSummary' => collect($lines)->map(fn ($l) => $l['name'] . ' ×' . $l['quantity'])->implode(', '),
            'lines' => $lines,
            'reason' => $reason,
            'restocked' => (bool) $r->restock,
            'condition' => $r->restock ? 'restock' : 'writeoff',
            'amount' => (float) $r->total_amount,
            'refundMethod' => $this->normalizeMethod((string) $r->refund_method),
            'refundedVia' => $r->refunded_via,
            'refundAmount' => (float) $r->refund_amount,
            'status' => $status,
            'isRequest' => (bool) $r->is_request,
            'locationId' => $r->location_id,
            'locationName' => $r->location_id ? ($locations[$r->location_id] ?? null) : null,
            'note' => $r->notes,
            'rejectionReason' => $r->rejection_reason,
            'processedAt' => $r->processed_date?->toIso8601String(),
        ];
    }

    private function logStats(int $companyId): array
    {
        $since = now()->subDays(30);
        $done = fn () => CustomerReturn::where('company_id', $companyId)->whereIn('status', self::DONE);

        $returns30 = $done()->where('created_at', '>=', $since)->count();
        $sales30 = Sell::where('company_id', $companyId)->whereRaw('LOWER(status) <> ?', ['cancelled'])
            ->where(DB::raw('COALESCE(order_time, created_at)'), '>=', $since)->count();

        $pending = CustomerReturn::where('company_id', $companyId)->where('status', 'pending');

        $units = (int) DB::table('customer_return_items as i')
            ->join('customer_returns as r', 'r.id', '=', 'i.return_id')
            ->where('r.company_id', $companyId)->whereNull('r.deleted_at')
            ->whereIn('r.status', self::DONE)->where('r.restock', true)
            ->sum('i.quantity');

        $refunded = $done()->whereIn('refund_method', ['cash', 'original', 'original_payment']);

        $top = DB::table('customer_return_items as i')
            ->join('customer_returns as r', 'r.id', '=', 'i.return_id')
            ->where('r.company_id', $companyId)->whereNull('r.deleted_at')
            ->whereIn('r.status', self::DONE)
            ->whereNotNull('i.reason')->where('i.reason', '<>', '')
            ->groupBy('i.reason')
            ->orderByRaw('COUNT(DISTINCT r.id) DESC')->orderBy('i.reason')
            ->selectRaw('i.reason as reason, COUNT(DISTINCT r.id) as n')
            ->first();

        return [
            'returns30d' => $returns30,
            'ordersPct30d' => $sales30 > 0 ? number_format($returns30 / $sales30 * 100, 1, '.', '') . '% of orders' : null,
            'pendingCount' => (clone $pending)->count(),
            'pendingAmount' => round((float) (clone $pending)->sum('total_amount'), 2),
            'restockedUnits' => $units,
            'refundedAmount' => round((float) (clone $refunded)->sum(DB::raw('CASE WHEN refund_amount > 0 THEN refund_amount ELSE total_amount END')), 2),
            'refundedCount' => (clone $refunded)->count(),
            'topReason' => $top?->reason,
            'topReasonCount' => $top ? (int) $top->n : 0,
            'writtenOffValue' => round((float) $done()->where('restock', false)->sum('write_off_value'), 2),
        ];
    }
    public function get(int $id, int $companyId): CustomerReturnDTO
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Customer return not found');
        }

        return $this->mapper->toDTO($return);
    }

    /** Full response (new row shape + legacy DTO fields) for one return. */
    public function getFull(int $id, int $companyId): array
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$return) {
            throw new HttpException(404, 'Customer return not found');
        }
        return $this->full($return);
    }

    private function full(CustomerReturn $return): array
    {
        $return->loadMissing('items');
        $legacy = $this->mapper->toDTO($return)->toArray();
        $locations = $return->location_id ? Location::whereKey($return->location_id)->pluck('name', 'id')->all() : [];
        return array_merge($legacy, $this->row($return, $locations));
    }

    public function getByCustomer(int $customerId, int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCustomer($customerId, $companyId, $filters);
        $data = array_map(fn ($return) => $this->mapper->toDTO($return), $paginated->items());
        return [
            'data' => $data,
            'pagination' => [
                'total' => $paginated->total(),
                'perPage' => $paginated->perPage(),
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
            ]
        ];
    }

    public function create(int $companyId, array $data): array
    {
        if (isset($data['mode']) || isset($data['restock']) || isset($data['locationId'])) {
            return $this->createFromOrder($companyId, $data);
        }
        return $this->createLegacy($companyId, $data);
    }

    private function createLegacy(int $companyId, array $data): array
    {
        $dbData = $this->mapInputToDb($data);
        $dbData['company_id'] = $companyId;
        $dbData['is_request'] = true;
        $dbData['status'] = 'pending';

        // Generate return number if not provided
        if (!isset($dbData['return_number']) || empty($dbData['return_number'])) {
            $dbData['return_number'] = 'RET-' . (int)(microtime(true) * 10000);
        }

        // Set request date if not provided
        if (!isset($dbData['request_date']) || empty($dbData['request_date'])) {
            $dbData['request_date'] = now();
        }

        $items = $data['items'] ?? [];
        $returnId = 0;

        DB::transaction(function () use (&$dbData, $items, &$returnId) {
            $return = CustomerReturn::create($dbData);
            $returnId = $return->id;

            // Create return items and auto-fill names
            foreach ($items as $item) {
                // Auto-fill product name if productId provided but name missing
                $productName = $item['productName'] ?? null;
                if (!$productName && isset($item['productId'])) {
                    $product = Product::find($item['productId']);
                    if ($product) {
                        $productName = $product->name;
                    }
                }

                // Auto-fill variant name if variantId provided but name missing
                $variantName = $item['variantName'] ?? null;
                if (!$variantName && isset($item['variantId'])) {
                    $variant = ProductVariant::find($item['variantId']);
                    if ($variant) {
                        $variantName = $variant->name;
                    }
                }

                $itemData = [
                    'return_id' => $return->id,
                    'product_id' => $item['productId'] ?? null,
                    'product_name' => $productName ?? $item['productName'],
                    'variant_id' => $item['variantId'] ?? null,
                    'variant_name' => $variantName,
                    'quantity' => $item['quantity'] ?? 1,
                    'price' => $item['price'] ?? 0,
                    'reason' => $item['reason'],
                ];

                CustomerReturnItem::create($itemData);
            }
        });

        $createdReturn = $this->getFull($returnId, $companyId);

        $this->notificationService->notifyReturnRequested(
            $companyId,
            $dbData['return_number'] ?? "RET-{$returnId}",
            $dbData['customer_name'] ?? 'Customer'
        );

        return $createdReturn;
    }

    public function update(int $id, int $companyId, array $data): CustomerReturnDTO
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Customer return not found');
        }

        $dbData = $this->mapInputToDb($data);

        $this->repository->update($id, $dbData);

        return $this->get($id, $companyId);
    }

    public function approve(int $id, int $companyId): array
    {
        DB::transaction(function () use ($id, $companyId) {
            $return = CustomerReturn::where('company_id', $companyId)->lockForUpdate()->find($id);
            if (!$return) {
                throw new HttpException(404, 'Customer return not found');
            }
            if ($return->status !== 'pending') {
                throw new HttpException(400, 'Only pending returns can be approved');
            }
            $this->assertMayRefund((string) $return->refund_method);
            $this->process($return);
        });

        return $this->getFull($id, $companyId);
    }

    public function reject(int $id, int $companyId, array $data = []): array
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Customer return not found');
        }

        if ($return->status !== 'pending') {
            throw new HttpException(400, 'Only pending returns can be rejected');
        }

        $reason = trim((string) ($data['reason'] ?? $data['notes'] ?? ''));
        if ($reason === '') {
            throw new HttpException(422, 'A reason is required to reject a return');
        }
        $updateData = [
            'status' => 'rejected',
            'processed_date' => now(),
            'rejection_reason' => $reason,
            'processed_by' => ActorName::current(),
        ];

        $this->repository->update($id, $updateData);

        return $this->getFull($id, $companyId);
    }

    // ------------------------------------------------------------------
    // Processing (stock + refund) — shared by "complete" mode and approve
    // ------------------------------------------------------------------

    /** Restock or write off, settle the refund, mark completed. Call inside a transaction. */
    private function process(CustomerReturn $return): void
    {
        $companyId = (int) $return->company_id;
        $items = CustomerReturnItem::where('return_id', $return->id)->get();
        $amount = round((float) $items->sum(fn ($i) => (float) $i->price * (int) $i->quantity), 2);
        if ($amount <= 0) {
            $amount = round((float) $return->total_amount, 2);
        }

        // ---- stock ----
        $writeOff = 0.0;
        if ($return->restock) {
            $locationId = $return->location_id ? (int) $return->location_id : $this->stock->defaultLocationId($companyId);
            if (!Location::where('company_id', $companyId)->whereKey($locationId)->exists()) {
                throw new HttpException(422, 'Restock location not found');
            }
            foreach ($items as $item) {
                if (!$item->product_id) {
                    continue;
                }
                $this->stock->restore($companyId, (int) $item->product_id, $item->variant_id ? (int) $item->variant_id : null, $locationId, (int) $item->quantity, [
                    'type' => 'return',
                    'reference_type' => 'customer_return',
                    'reference_id' => $return->id,
                    'reason' => 'Customer return',
                    'notes' => $return->return_number,
                    'unit_cost' => $item->unit_cost,
                ]);
            }
            $return->location_id = $locationId;
        } else {
            foreach ($items as $item) {
                $cost = $item->unit_cost;
                if ($cost === null) {
                    $cost = $item->variant_id
                        ? ProductVariant::whereKey($item->variant_id)->value('cost_price')
                        : null;
                    if (!$cost && $item->product_id) {
                        $cost = Product::whereKey($item->product_id)->value('cost_price');
                    }
                }
                $writeOff += (int) $item->quantity * (float) ($cost ?? 0);
            }
        }

        // ---- refund ----
        $method = $this->normalizeMethod((string) $return->refund_method);
        $refund = 0.0;
        $credited = false;
        if ($method === 'credit') {
            if (!$return->customer_id) {
                throw new HttpException(422, 'Store credit needs a named customer. Use cash refund for walk-in sales.');
            }
            $customer = Customer::where('company_id', $companyId)->lockForUpdate()->find($return->customer_id);
            if (!$customer) {
                throw new HttpException(422, 'Store credit needs a named customer. Use cash refund for walk-in sales.');
            }
            $customer->store_credit = round((float) $customer->store_credit + $amount, 2);
            $customer->save();
            $credited = true;
        } elseif ($method === 'cash') {
            $refund = $amount;
        }

        $refundedVia = null;
        if ($return->order_id && in_array($method, ['original', 'none'], true)) {
            $sell = Sell::where('company_id', $companyId)->lockForUpdate()->find($return->order_id);
            if ($sell) {
                if ($method === 'original') {
                    [$refund, $refundedVia] = $this->reversePayments($sell, $amount, $return);
                }
                // The returned goods leave the invoice, so its total drops by the value returned.
                $sell->amount = max(0, round((float) $sell->amount - $amount, 2));
                $sell->save();
                $sell->recalculate();
            } elseif ($method === 'original') {
                $refund = $amount;
            }
        } elseif ($method === 'original') {
            $refund = $amount;
        }

        $return->fill([
            'refund_method' => $method,
            'subtotal' => $amount,
            'total_amount' => $amount,
            'refund_amount' => $refund,
            'refunded_via' => $refundedVia,
            'write_off_value' => round($writeOff, 2),
            'credited_to_customer' => $credited,
            'status' => 'completed',
            'processed_date' => now(),
            'processed_by' => ActorName::current(),
        ]);
        $return->save();
    }

    /**
     * Reverse (up to $amount of) the sale's incoming payments, newest first, one "out" row per
     * original payment so the method is preserved. Returns [reversed amount, method label].
     */
    private function reversePayments(Sell $sell, float $amount, CustomerReturn $return): array
    {
        $remaining = round($amount, 2);
        $reversed = 0.0;
        $methods = [];
        $payments = $sell->payments()->where('direction', 'in')->orderByDesc('payment_date')->orderByDesc('id')->get();
        $alreadyOut = (float) $sell->payments()->where('direction', 'out')->sum('amount');
        // payments already reversed are consumed oldest-first of the net balance: only refund what is still held
        $held = max(0, round((float) $payments->sum('amount') - $alreadyOut, 2));
        $remaining = min($remaining, $held);
        foreach ($payments as $p) {
            if ($remaining <= 0) {
                break;
            }
            $take = round(min($remaining, (float) $p->amount), 2);
            if ($take <= 0) {
                continue;
            }
            \App\Models\DuePayment::create([
                'company_id' => $sell->company_id,
                'payable_type' => Sell::class,
                'payable_id' => $sell->id,
                'direction' => 'out',
                'amount' => $take,
                'payment_method' => $p->payment_method,
                'payment_date' => now()->toDateString(),
                'reference' => $return->return_number,
                'notes' => 'Refund for return ' . $return->return_number,
                'recorded_by' => null,
            ]);
            $remaining = round($remaining - $take, 2);
            $reversed = round($reversed + $take, 2);
            $methods[$p->payment_method] = true;
        }
        $label = $methods ? implode(', ', array_map(fn ($m) => ucfirst((string) $m), array_keys($methods))) : null;
        return [$reversed, $label];
    }

    /**
     * sellItemId => unit price net of the line discount and that line's share of the order discount.
     */
    private function netUnitPrices(Sell $sell, $sellItems): array
    {
        $lineNet = [];
        $sum = 0.0;
        foreach ($sellItems as $oi) {
            $net = max(0.0, (float) $oi->unit_price * (int) $oi->quantity - (float) $oi->discount);
            $lineNet[$oi->id] = $net;
            $sum += $net;
        }
        $orderDiscount = min((float) $sell->discount, $sum);
        $out = [];
        foreach ($sellItems as $oi) {
            $qty = max(1, (int) $oi->quantity);
            $share = $sum > 0 ? $orderDiscount * $lineNet[$oi->id] / $sum : 0.0;
            $out[$oi->id] = round(max(0.0, $lineNet[$oi->id] - $share) / $qty, 2);
        }
        return $out;
    }

    /** Cash / original-payment refunds hand money back, so they need the role's `refund` flag. */
    private function assertMayRefund(string $method): void
    {
        if (!in_array($this->normalizeMethod($method), ['credit', 'none'], true)) {
            \App\Support\Sensitive::require('refund', 'You do not have permission to approve refunds');
        }
    }

    private function normalizeMethod(string $m): string
    {
        return match ($m) {
            'store_credit' => 'credit',
            'original_payment' => 'original',
            default => $m,
        };
    }

    // ------------------------------------------------------------------
    // New flow: create from an order
    // ------------------------------------------------------------------

    private function createFromOrder(int $companyId, array $data): array
    {
        $mode = $data['mode'] ?? 'complete';
        if (!in_array($mode, ['complete', 'request'], true)) {
            throw new HttpException(422, 'mode must be complete or request');
        }
        $method = $this->normalizeMethod((string) ($data['refundMethod'] ?? 'cash'));
        if (!in_array($method, ['cash', 'original', 'credit', 'none'], true)) {
            throw new HttpException(422, 'refundMethod must be cash, original, credit or none');
        }
        if ($mode === 'complete') {
            $this->assertMayRefund($method);
        }
        if (empty($data['orderId'])) {
            throw new HttpException(422, 'orderId is required');
        }
        $reqItems = $data['items'] ?? [];
        if (!is_array($reqItems) || !count($reqItems)) {
            throw new HttpException(422, 'Select at least one item to return');
        }
        $restock = array_key_exists('restock', $data) ? filter_var($data['restock'], FILTER_VALIDATE_BOOLEAN) : true;

        $returnId = 0;
        $customerName = 'Customer';
        $returnNumber = '';

        DB::transaction(function () use ($companyId, $data, $mode, $method, $reqItems, $restock, &$returnId, &$customerName, &$returnNumber) {
            $sell = Sell::where('company_id', $companyId)->lockForUpdate()->find((int) $data['orderId']);
            if (!$sell) {
                throw new HttpException(404, 'Order not found');
            }
            if (strtolower((string) $sell->status) === 'cancelled') {
                throw new HttpException(422, 'Cancelled orders cannot be returned');
            }
            if ($method === 'credit' && !$sell->customer_id) {
                throw new HttpException(422, 'Store credit needs a named customer. Use cash refund for walk-in sales.');
            }

            $sellItems = OrderItem::where('sell_id', $sell->id)->get();
            $returned = $this->returnedBySellItem($sell->id, $sellItems);
            $net = $this->netUnitPrices($sell, $sellItems);

            $lines = [];
            $asked = []; // sellItemId => qty requested in this call
            foreach ($reqItems as $ri) {
                $qty = (int) ($ri['quantity'] ?? 0);
                $reason = trim((string) ($ri['reason'] ?? ''));
                $oi = null;
                if (!empty($ri['sellItemId'])) {
                    $oi = $sellItems->firstWhere('id', (int) $ri['sellItemId']);
                } else {
                    $oi = $sellItems->first(fn ($x) => (int) $x->product_id === (int) ($ri['productId'] ?? 0)
                        && (int) ($x->variant_id ?? 0) === (int) ($ri['variantId'] ?? 0));
                }
                if (!$oi) {
                    throw new HttpException(422, 'Item does not belong to this order');
                }
                $name = $this->itemName($oi);
                if ($qty < 1) {
                    throw new HttpException(422, "Quantity for {$name} must be at least 1");
                }
                if ($reason === '') {
                    throw new HttpException(422, "Reason is required for {$name}");
                }
                $asked[$oi->id] = ($asked[$oi->id] ?? 0) + $qty;
                $returnable = (int) $oi->quantity - ($returned[$oi->id] ?? 0);
                if ($asked[$oi->id] > $returnable) {
                    throw new HttpException(422, "Cannot return {$asked[$oi->id]} x {$name}: only {$returnable} left to return");
                }
                $lines[] = [$oi, $qty, $reason];
            }

            $subtotal = 0.0;
            foreach ($lines as [$oi, $qty]) {
                $subtotal += $qty * $net[$oi->id];
            }
            $subtotal = round($subtotal, 2);

            $locationId = !empty($data['locationId']) ? (int) $data['locationId'] : null;
            if ($locationId && !Location::where('company_id', $companyId)->whereKey($locationId)->exists()) {
                throw new HttpException(422, 'Location not found');
            }
            $locationId = $locationId ?: ($sell->location_id ? (int) $sell->location_id : $this->stock->defaultLocationId($companyId));

            $reasons = collect($lines)->map(fn ($l) => $l[2])->unique()->values()->implode(', ');

            $customerName = $sell->customer_name ?: 'Walk-in';
            $return = CustomerReturn::create([
                'company_id' => $companyId,
                'return_number' => 'RET-' . (int) (microtime(true) * 10000),
                'customer_id' => $sell->customer_id,
                'customer_name' => $customerName,
                'order_id' => $sell->id,
                'order_number' => $sell->invoice_no,
                'location_id' => $locationId,
                'restock' => $restock,
                'total_amount' => $subtotal,
                'subtotal' => $subtotal,
                'refund_amount' => 0,
                'status' => 'pending',
                'is_request' => $mode === 'request',
                'request_date' => now(),
                'refund_method' => $method,
                'reason' => mb_substr($reasons, 0, 1000),
                'notes' => isset($data['note']) ? (trim((string) $data['note']) ?: null) : null,
            ]);
            $returnId = $return->id;
            $returnNumber = $return->return_number;

            foreach ($lines as [$oi, $qty, $reason]) {
                $cost = $oi->unit_cost;
                if ($cost === null || (float) $cost <= 0) {
                    $cost = $oi->variant_id ? ProductVariant::whereKey($oi->variant_id)->value('cost_price') : null;
                    if (!$cost && $oi->product_id) {
                        $cost = Product::whereKey($oi->product_id)->value('cost_price');
                    }
                }
                CustomerReturnItem::create([
                    'return_id' => $return->id,
                    'sell_item_id' => $oi->id,
                    'product_id' => $oi->product_id,
                    'product_name' => $oi->product_name,
                    'variant_id' => $oi->variant_id,
                    'variant_name' => $oi->variant_name,
                    'quantity' => $qty,
                    'price' => $net[$oi->id],
                    'unit_cost' => $cost !== null ? round((float) $cost, 2) : null,
                    'reason' => mb_substr($reason, 0, 255),
                ]);
            }

            if ($mode === 'complete') {
                $this->process($return);
            }
        });

        if ($mode === 'request') {
            $this->notificationService->notifyReturnRequested($companyId, $returnNumber, $customerName);
        }

        return $this->getFull($returnId, $companyId);
    }

    /** sell_item_id => quantity already in pending/completed returns. */
    private function returnedBySellItem(int $sellId, $sellItems): array
    {
        $rows = DB::table('customer_return_items as i')
            ->join('customer_returns as r', 'r.id', '=', 'i.return_id')
            ->whereNull('r.deleted_at')
            ->whereIn('r.status', ['pending', 'completed', 'approved'])
            ->where(function ($q) use ($sellId) {
                $q->whereIn('i.sell_item_id', DB::table('order_items')->where('sell_id', $sellId)->pluck('id'))
                    ->orWhere(fn ($q2) => $q2->whereNull('i.sell_item_id')->where('r.order_id', $sellId));
            })
            ->get(['i.sell_item_id', 'i.product_id', 'i.variant_id', 'i.quantity']);

        $out = [];
        foreach ($rows as $row) {
            if ($row->sell_item_id) {
                $out[$row->sell_item_id] = ($out[$row->sell_item_id] ?? 0) + (int) $row->quantity;
                continue;
            }
            // legacy line without sell_item_id: match by product/variant
            $oi = $sellItems->first(fn ($x) => (int) $x->product_id === (int) $row->product_id
                && (int) ($x->variant_id ?? 0) === (int) ($row->variant_id ?? 0));
            if ($oi) {
                $out[$oi->id] = ($out[$oi->id] ?? 0) + (int) $row->quantity;
            }
        }
        return $out;
    }

    private function itemName(OrderItem $oi): string
    {
        $name = (string) $oi->product_name;
        if ($oi->variant_name && $oi->variant_name !== 'Default') {
            $name .= ' - ' . $oi->variant_name;
        }
        return $name;
    }

    // ------------------------------------------------------------------
    // Returnable orders
    // ------------------------------------------------------------------

    public function returnableOrders(int $companyId, array $filters): array
    {
        $limit = min(max((int) ($filters['limit'] ?? 20), 1), 100);
        $search = trim((string) ($filters['search'] ?? ''));

        $base = Sell::where('company_id', $companyId)
            ->whereRaw('LOWER(status) <> ?', ['cancelled'])
            ->orderByDesc('id');
        if ($search !== '') {
            $base->where(function ($q) use ($search) {
                $q->where('invoice_no', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        $result = [];
        $scanned = 0;
        $page = 1;
        while (count($result) < $limit && $scanned < 500) {
            $sells = (clone $base)->with('items')->forPage($page++, 50)->get();
            if ($sells->isEmpty()) {
                break;
            }
            $scanned += $sells->count();
            $returnedMap = $this->returnedForSells($sells);

            foreach ($sells as $sell) {
                $returned = $returnedMap[$sell->id] ?? [];
                $net = $this->netUnitPrices($sell, $sell->items);
                $items = [];
                $any = false;
                foreach ($sell->items as $oi) {
                    $ret = (int) ($returned[$oi->id] ?? 0);
                    $left = max(0, (int) $oi->quantity - $ret);
                    if ($left > 0) {
                        $any = true;
                    }
                    $items[] = [
                        'sellItemId' => $oi->id,
                        'productId' => $oi->product_id,
                        'variantId' => $oi->variant_id,
                        'name' => $this->itemName($oi),
                        'bought' => (int) $oi->quantity,
                        'returned' => $ret,
                        'returnable' => $left,
                        'price' => $net[$oi->id] ?? (float) $oi->unit_price,
                        'listPrice' => (float) $oi->unit_price,
                    ];
                }
                if (!$any) {
                    continue;
                }
                $when = $sell->order_time ?? $sell->created_at;
                $result[] = [
                    'id' => $sell->id,
                    'invoiceNo' => $sell->invoice_no,
                    'customerId' => $sell->customer_id,
                    'customerName' => $sell->customer_name ?: 'Walk-in',
                    'date' => $when?->toIso8601String(),
                    'amount' => (float) $sell->amount,
                    'ageDays' => $when ? (int) $when->diffInDays(now()) : 0,
                    'locationId' => $sell->location_id,
                    'paidVia' => $this->paidVia($sell),
                    'items' => $items,
                ];
                if (count($result) >= $limit) {
                    break;
                }
            }
        }

        return $result;
    }

    /** Method label(s) the sale was paid with (falls back to the sale's own method). */
    private function paidVia(Sell $sell): ?string
    {
        $methods = $sell->payments()->where('direction', 'in')->orderBy('id')->pluck('payment_method')->filter()->unique()->values();
        if ($methods->isEmpty()) {
            return $sell->method ? ucfirst((string) $sell->method) : null;
        }
        return $methods->map(fn ($m) => ucfirst((string) $m))->implode(', ');
    }

    /** sellId => [sellItemId => returned qty] for a batch of sells. */
    private function returnedForSells($sells): array
    {
        $out = [];
        foreach ($sells as $sell) {
            $out[$sell->id] = $this->returnedBySellItem($sell->id, $sell->items);
        }
        return $out;
    }

    public function delete(int $id, int $companyId): void
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Customer return not found');
        }

        $this->repository->delete($id);
    }

    public function getStats(int $companyId): array
    {
        return $this->repository->getStats($companyId);
    }

    private function restoreBundleStock(Product $bundle, int $returnQty): void
    {
        $bundleItems = ProductBundleItem::where('bundle_product_id', $bundle->id)
            ->with(['product', 'variant'])
            ->get();

        foreach ($bundleItems as $bi) {
            $restoreQty = $bi->quantity * $returnQty;

            if ($bi->variant_id && $bi->variant) {
                $bi->variant->increment('stock', $restoreQty);
                $childProduct = $bi->variant->product;
                if ($childProduct) {
                    $childProduct->stock = \App\Models\ProductVariant::where('product_id', $childProduct->id)->sum('stock');
                    $childProduct->save();
                }
            } elseif ($bi->product) {
                $bi->product->increment('stock', $restoreQty);
            }
        }

        // Re-sync bundle stock = MIN(floor(childStock / childQty))
        $bundle->refresh();
        $items = ProductBundleItem::where('bundle_product_id', $bundle->id)
            ->with(['product', 'variant'])
            ->get();

        $available = null;
        foreach ($items as $item) {
            $childStock = $item->variant_id && $item->variant
                ? (int) $item->variant->stock
                : (int) ($item->product->stock ?? 0);
            $slots = (int) floor($childStock / max(1, $item->quantity));
            $available = $available === null ? $slots : min($available, $slots);
        }
        $bundle->update(['stock' => max(0, $available ?? 0)]);
    }

    private function mapInputToDb(array $data): array
    {
        $dbData = [];

        if (isset($data['returnNumber'])) {
            $dbData['return_number'] = $data['returnNumber'];
        }
        if (isset($data['customerId'])) {
            $dbData['customer_id'] = $data['customerId'];
        }
        if (isset($data['customerName'])) {
            $dbData['customer_name'] = $data['customerName'];
        }
        if (isset($data['orderId'])) {
            $dbData['order_id'] = $data['orderId'];
        }
        if (isset($data['orderNumber'])) {
            $dbData['order_number'] = $data['orderNumber'];
        }
        if (isset($data['totalAmount'])) {
            $dbData['total_amount'] = $data['totalAmount'];
        }
        if (isset($data['status'])) {
            $dbData['status'] = $data['status'];
        }
        if (isset($data['requestDate'])) {
            $dbData['request_date'] = $data['requestDate'];
        }
        if (isset($data['refundMethod'])) {
            $dbData['refund_method'] = $data['refundMethod'];
        }
        if (isset($data['notes'])) {
            $dbData['notes'] = $data['notes'];
        }
        if (isset($data['processedBy'])) {
            $dbData['processed_by'] = $data['processedBy'];
        }

        return $dbData;
    }
}
