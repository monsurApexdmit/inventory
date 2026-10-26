<?php

namespace App\Services\VendorReturn;

use App\DTOs\VendorReturn\VendorReturnDTO;
use App\DTOs\VendorReturn\VendorReturnMapper;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\Inventory\LocationStockService;
use App\Services\Vendor\VendorService;
use App\Support\ActorName;
use App\Models\ProductVariant;
use App\Models\VendorReturn;
use App\Models\VendorReturnItem;
use App\Repositories\Contracts\IVendorReturnRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VendorReturnService
{
    private readonly VendorReturnMapper $mapper;

    public function __construct(
        private readonly IVendorReturnRepository $repository,
        private readonly LocationStockService $stock,
        private readonly VendorService $vendorService,
    ) {
        $this->mapper = new VendorReturnMapper();
    }

    private const FLOW = ['pending', 'shipped', 'received', 'credited'];

    public static function normalizeStatus(?string $s): string
    {
        return match ($s) {
            'completed' => 'credited',
            'received_by_vendor' => 'received',
            null, '' => 'pending',
            default => $s,
        };
    }

    public static function normalizeCredit(?string $c): string
    {
        return match ($c) {
            'credit_note', null, '' => 'note',
            default => $c,
        };
    }

    private function filteredQuery(int $companyId, array $filters)
    {
        $q = VendorReturn::where('company_id', $companyId);

        if (!empty($filters['vendor_id'])) {
            $q->where('vendor_id', (int) $filters['vendor_id']);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('return_number', 'like', "%{$search}%")
                    ->orWhere('vendor_name', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('tracking_no', 'like', "%{$search}%")
                    ->orWhereIn('purchase_order_id', PurchaseOrder::where('company_id', $companyId)->where('po_number', 'like', "%{$search}%")->select('id'));
            });
        }
        if (!empty($filters['from'])) {
            $q->whereDate('return_date', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q->whereDate('return_date', '<=', $filters['to']);
        }
        return $q;
    }

    private function applyStatus($q, string $status)
    {
        return match ($status) {
            'pending' => $q->where('status', 'pending'),
            'shipped' => $q->where('status', 'shipped'),
            'received' => $q->whereIn('status', ['received', 'received_by_vendor']),
            'credited' => $q->whereIn('status', ['credited', 'completed']),
            default => $q,
        };
    }

    public function list(int $companyId, array $filters): array
    {
        $status = (string) ($filters['status'] ?? 'all');
        $perPage = min(max((int) ($filters['per_page'] ?? $filters['limit'] ?? 10), 1), 500);

        $paginated = $this->applyStatus($this->filteredQuery($companyId, $filters), $status)
            ->with('items')
            ->orderByDesc('return_date')->orderByDesc('id')
            ->paginate($perPage);

        $lookups = $this->lookups(collect($paginated->items()));
        $rows = array_map(fn ($r) => $this->row($r, $lookups), $paginated->items());

        $counts = [];
        foreach (['all', 'pending', 'shipped', 'received', 'credited'] as $st) {
            $counts[$st] = $this->applyStatus($this->filteredQuery($companyId, $filters), $st)->count();
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

    private function lookups($returns): array
    {
        $locIds = $returns->pluck('location_id')->filter()->unique()->all();
        $poIds = $returns->pluck('purchase_order_id')->filter()->unique()->all();
        return [
            'locations' => $locIds ? Location::whereIn('id', $locIds)->pluck('name', 'id')->all() : [],
            'pos' => $poIds ? PurchaseOrder::whereIn('id', $poIds)->pluck('po_number', 'id')->all() : [],
        ];
    }

    private function row(VendorReturn $r, array $lookups): array
    {
        $r->loadMissing(['items', 'attachments']);
        $lines = $r->items->map(function ($i) {
            $name = (string) $i->product_name;
            if ($i->variant_name && $i->variant_name !== 'Default') {
                $name .= ' - ' . $i->variant_name;
            }
            $cost = (float) ($i->unit_cost ?: $i->unit_price);
            return [
                'name' => $name,
                'quantity' => (int) $i->quantity,
                'unitCost' => $cost,
                'total' => round($cost * (int) $i->quantity, 2),
            ];
        })->values()->all();

        $reason = $r->reason ?: ($r->items->pluck('reason')->filter()->unique()->implode(', ') ?: null);

        return [
            'id' => $r->id,
            'returnNumber' => $r->return_number,
            'returnDate' => $r->return_date?->toDateString(),
            'createdAt' => $r->created_at?->toIso8601String(),
            'vendorId' => $r->vendor_id,
            'supplierName' => $r->vendor_name,
            'purchaseOrderId' => $r->purchase_order_id,
            'poNumber' => $r->purchase_order_id ? ($lookups['pos'][$r->purchase_order_id] ?? null) : null,
            'itemsSummary' => collect($lines)->map(fn ($l) => $l['name'] . ' ×' . $l['quantity'])->implode(', '),
            'lines' => $lines,
            'reason' => $reason,
            'amount' => (float) $r->total_amount,
            'creditType' => self::normalizeCredit($r->credit_type),
            'status' => self::normalizeStatus($r->status),
            'locationId' => $r->location_id,
            'locationName' => $r->location_id ? ($lookups['locations'][$r->location_id] ?? null) : null,
            'trackingNo' => $r->tracking_no,
            'shippedAt' => $r->shipped_at?->toIso8601String(),
            'receivedAt' => $r->received_at?->toIso8601String(),
            'creditedAt' => $r->credited_at?->toIso8601String(),
            'note' => $r->notes,
            'attachments' => $r->attachments->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'mime' => $a->mime,
                'size' => (int) $a->size,
                'url' => \Illuminate\Support\Facades\Storage::disk('public')->url($a->path),
            ])->values()->all(),
        ];
    }

    public function addAttachments(int $id, int $companyId, array $files): array
    {
        $return = VendorReturn::where('company_id', $companyId)->find($id);
        if (!$return) {
            throw new HttpException(404, 'Vendor return not found');
        }
        foreach ($files as $file) {
            \App\Models\VendorReturnAttachment::create([
                'company_id' => $companyId,
                'vendor_return_id' => $return->id,
                'path' => $file->store('vendor-returns', 'public'),
                'name' => mb_substr($file->getClientOriginalName(), 0, 250),
                'mime' => $file->getClientMimeType(),
                'size' => (int) $file->getSize(),
            ]);
        }
        return $this->getFull($id, $companyId);
    }

    public function removeAttachment(int $id, int $attachmentId, int $companyId): array
    {
        $att = \App\Models\VendorReturnAttachment::where('company_id', $companyId)
            ->where('vendor_return_id', $id)->find($attachmentId);
        if (!$att) {
            throw new HttpException(404, 'Attachment not found');
        }
        \Illuminate\Support\Facades\Storage::disk('public')->delete($att->path);
        $att->delete();
        return $this->getFull($id, $companyId);
    }

    private function logStats(int $companyId): array
    {
        $all = VendorReturn::where('company_id', $companyId)->get(['vendor_id', 'vendor_name', 'status', 'credit_type', 'total_amount']);
        $notCredited = $all->filter(fn ($r) => self::normalizeStatus($r->status) !== 'credited');
        $credited = $all->filter(fn ($r) => self::normalizeStatus($r->status) === 'credited');

        $byVendor = $all->filter(fn ($r) => $r->vendor_name)->groupBy('vendor_name')->map->count()->sortDesc();
        $topName = $byVendor->keys()->first();

        return [
            'count' => $all->count(),
            'pendingCount' => $all->filter(fn ($r) => self::normalizeStatus($r->status) === 'pending')->count(),
            'shippedCount' => $all->filter(fn ($r) => self::normalizeStatus($r->status) === 'shipped')->count(),
            'creditToReceive' => round((float) $notCredited->filter(fn ($r) => in_array(self::normalizeCredit($r->credit_type), ['note', 'refund', 'replacement'], true))->sum('total_amount'), 2),
            'creditReceived' => round((float) $credited->sum('total_amount'), 2),
            'suppliers' => $all->filter(fn ($r) => $r->vendor_id && $r->vendor_name)->unique('vendor_id')->sortBy('vendor_name')->map(fn ($r) => ['id' => (int) $r->vendor_id, 'name' => $r->vendor_name])->values()->all(),
            'mostReturnedTo' => $topName !== null ? ['name' => $topName, 'count' => (int) $byVendor[$topName]] : null,
            'pendingCreditAmount' => round((float) $notCredited->sum('total_amount'), 2),
        ];
    }

    // ------------------------------------------------------------------
    // Returnable items
    // ------------------------------------------------------------------

    public function returnableItems(int $companyId, array $filters): array
    {
        $locationId = !empty($filters['location_id']) ? (int) $filters['location_id'] : $this->stock->defaultLocationId($companyId);
        $vendorId = !empty($filters['vendor_id']) ? (int) $filters['vendor_id'] : null;
        $poId = !empty($filters['po_id']) ? (int) $filters['po_id'] : null;
        $search = trim((string) ($filters['search'] ?? ''));

        $like = $search !== '' ? "%{$search}%" : null;
        $items = [];

        // Variant rows (real variants and the hidden "Default" variant of simple products)
        $variantRows = DB::table('product_variants as pv')
            ->join('products as p', 'pv.product_id', '=', 'p.id')
            ->join('variant_inventory as vi', 'pv.id', '=', 'vi.variant_id')
            ->where('p.company_id', $companyId)->where('vi.location_id', $locationId)->where('vi.quantity', '>', 0)
            ->where('p.is_bundle', false)
            ->whereNull('p.deleted_at')->whereNull('pv.deleted_at')
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('p.name', 'like', $like)->orWhere('p.sku', 'like', $like)->orWhere('pv.sku', 'like', $like)->orWhere('pv.name', 'like', $like)))
            ->get(['p.id as product_id', 'p.name as product_name', 'p.sku as product_sku', 'p.cost_price as product_cost',
                'pv.id as variant_id', 'pv.name as variant_name', 'pv.sku as variant_sku', 'pv.cost_price as variant_cost', 'vi.quantity as stock']);
        foreach ($variantRows as $v) {
            $simple = $v->variant_name === 'Default';
            $items[] = [
                'productId' => (int) $v->product_id,
                'variantId' => $simple ? null : (int) $v->variant_id,
                'name' => $simple ? $v->product_name : $v->product_name . ' - ' . $v->variant_name,
                'sku' => (string) ($simple ? $v->product_sku : ($v->variant_sku ?: $v->product_sku)),
                'stock' => (int) $v->stock,
                'lastCost' => (float) ($simple ? $v->product_cost : ($v->variant_cost ?: $v->product_cost)),
                'receivedQty' => null,
            ];
        }

        // Legacy simple products that still keep stock on products.stock
        $simpleRows = DB::table('products as p')
            ->where('p.company_id', $companyId)->where('p.location_id', $locationId)->where('p.stock', '>', 0)
            ->where('p.is_bundle', false)->whereNull('p.deleted_at')
            ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))->from('product_variants as x')->whereColumn('x.product_id', 'p.id')->whereNull('x.deleted_at'))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('p.name', 'like', $like)->orWhere('p.sku', 'like', $like)))
            ->get(['p.id', 'p.name', 'p.sku', 'p.stock', 'p.cost_price']);
        foreach ($simpleRows as $p) {
            $items[] = [
                'productId' => (int) $p->id, 'variantId' => null, 'name' => $p->name, 'sku' => (string) $p->sku,
                'stock' => (int) $p->stock, 'lastCost' => (float) $p->cost_price, 'receivedQty' => null,
            ];
        }

        // Purchase-order restriction + received quantities
        $poLines = [];
        if ($poId) {
            $po = PurchaseOrder::where('company_id', $companyId)->find($poId);
            if (!$po) {
                throw new HttpException(404, 'Purchase order not found');
            }
            foreach (DB::table('purchase_order_items')->where('purchase_order_id', $poId)->get(['product_id', 'variant_id', 'quantity_received', 'unit_cost']) as $l) {
                $k = $l->product_id . ':' . (int) $l->variant_id;
                $poLines[$k] = [
                    'received' => ($poLines[$k]['received'] ?? 0) + (int) $l->quantity_received,
                    'cost' => (float) $l->unit_cost,
                ];
            }
            $items = array_values(array_filter($items, fn ($i) => isset($poLines[$i['productId'] . ':' . (int) $i['variantId']])));
            foreach ($items as &$i) {
                $line = $poLines[$i['productId'] . ':' . (int) $i['variantId']];
                $i['receivedQty'] = $line['received'];
                $i['lastCost'] = $line['cost'] > 0 ? $line['cost'] : $i['lastCost'];
            }
            unset($i);
        } elseif ($items) {
            // last purchase cost (this vendor if given, otherwise any)
            $pids = array_values(array_unique(array_column($items, 'productId')));
            $rows = DB::table('purchase_order_items as poi')
                ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
                ->where('po.company_id', $companyId)->whereIn('poi.product_id', $pids)->where('poi.unit_cost', '>', 0)
                ->when($vendorId, fn ($q) => $q->where('po.vendor_id', $vendorId))
                ->orderByDesc('po.id')->orderByDesc('poi.id')
                ->get(['poi.product_id', 'poi.variant_id', 'poi.unit_cost']);
            $last = [];
            foreach ($rows as $r) {
                $last[$r->product_id . ':' . (int) $r->variant_id] ??= (float) $r->unit_cost;
            }
            foreach ($items as &$i) {
                $k = $i['productId'] . ':' . (int) $i['variantId'];
                if (isset($last[$k])) {
                    $i['lastCost'] = $last[$k];
                }
            }
            unset($i);
        }

        usort($items, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return array_slice($items, 0, 200);
    }
    public function get(int $id, int $companyId): VendorReturnDTO
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Vendor return not found');
        }

        return $this->mapper->toDTO($return);
    }

    /** Full response: new row shape merged over the legacy DTO fields. */
    public function getFull(int $id, int $companyId): array
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$return) {
            throw new HttpException(404, 'Vendor return not found');
        }
        return $this->full($return);
    }

    private function full(VendorReturn $return): array
    {
        $legacy = $this->mapper->toDTO($return)->toArray();
        return array_merge($legacy, $this->row($return, $this->lookups(collect([$return]))));
    }

    public function getByVendor(int $vendorId, int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByVendor($vendorId, $companyId, $filters);
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
        $items = $data['items'] ?? [];
        if (!is_array($items) || !count($items)) {
            throw new HttpException(422, 'Select at least one item to return');
        }

        $returnDate = !empty($data['returnDate']) ? \Illuminate\Support\Carbon::parse($data['returnDate']) : now();
        if ($returnDate->copy()->startOfDay()->gt(now()->endOfDay())) {
            throw new HttpException(422, 'Return date cannot be in the future');
        }

        $returnId = 0;

        DB::transaction(function () use ($companyId, $data, $items, $returnDate, &$returnId) {
            $vendor = Vendor::where('company_id', $companyId)->find((int) ($data['vendorId'] ?? 0));
            if (!$vendor) {
                throw new HttpException(422, 'Supplier not found');
            }

            $poId = !empty($data['purchaseOrderId']) ? (int) $data['purchaseOrderId'] : null;
            if ($poId && !PurchaseOrder::where('company_id', $companyId)->where('vendor_id', $vendor->id)->whereKey($poId)->exists()) {
                throw new HttpException(422, 'Purchase order not found for this supplier');
            }

            $locationId = !empty($data['locationId']) ? (int) $data['locationId'] : $this->stock->defaultLocationId($companyId);
            $location = Location::where('company_id', $companyId)->find($locationId);
            if (!$location) {
                throw new HttpException(422, 'Location not found');
            }

            $lines = [];
            $need = []; // "product:variant" => qty
            foreach ($items as $it) {
                $product = Product::where('company_id', $companyId)->find((int) ($it['productId'] ?? 0));
                if (!$product) {
                    throw new HttpException(422, 'Product not found');
                }
                if ($product->is_bundle) {
                    throw new HttpException(422, "Bundle '{$product->name}' cannot be returned to a supplier");
                }
                $variant = null;
                if (!empty($it['variantId'])) {
                    $variant = ProductVariant::where('product_id', $product->id)->find((int) $it['variantId']);
                    if (!$variant) {
                        throw new HttpException(422, "Variant not found for '{$product->name}'");
                    }
                }
                $name = $product->name . ($variant ? ' - ' . $variant->name : '');
                $qty = (int) ($it['quantity'] ?? 0);
                if ($qty < 1) {
                    throw new HttpException(422, "Quantity for {$name} must be at least 1");
                }
                $reason = trim((string) ($it['reason'] ?? ''));
                if ($reason === '') {
                    throw new HttpException(422, "Reason is required for {$name}");
                }
                $cost = $it['unitCost'] ?? $it['unitPrice'] ?? null;
                if ($cost === null || !is_numeric($cost) || (float) $cost < 0) {
                    throw new HttpException(422, "Unit cost for {$name} must be 0 or more");
                }
                $key = $product->id . ':' . ($variant?->id ?? 0);
                $need[$key] = ($need[$key] ?? 0) + $qty;
                $avail = $this->stock->available($product->id, $variant?->id, $locationId);
                if ($need[$key] > $avail) {
                    throw new HttpException(422, "Not enough stock for {$name} at {$location->name} (available: {$avail}, requested: {$need[$key]})");
                }
                $lines[] = compact('product', 'variant', 'name', 'qty', 'reason') + ['cost' => round((float) $cost, 2)];
            }

            $total = round(collect($lines)->sum(fn ($l) => $l['qty'] * $l['cost']), 2);
            $reasons = collect($lines)->pluck('reason')->unique()->values()->implode(', ');

            $number = !empty($data['returnNumber']) ? $data['returnNumber'] : 'VRT-' . (int) (microtime(true) * 10000);
            $return = VendorReturn::create([
                'company_id' => $companyId,
                'return_number' => $number,
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->name,
                'location_id' => $locationId,
                'purchase_order_id' => $poId,
                'total_amount' => $total,
                'transport_fee' => 0,
                'status' => 'pending',
                'return_date' => $returnDate,
                'credit_type' => self::normalizeCredit($data['creditType'] ?? null),
                'tracking_no' => !empty($data['trackingNo']) ? trim((string) $data['trackingNo']) : null,
                'reason' => mb_substr($reasons, 0, 1000),
                'notes' => isset($data['note']) ? (trim((string) $data['note']) ?: null) : ($data['notes'] ?? null),
                'created_by' => $data['createdBy'] ?? ActorName::current(),
            ]);
            $returnId = $return->id;

            foreach ($lines as $l) {
                VendorReturnItem::create([
                    'return_id' => $return->id,
                    'product_id' => $l['product']->id,
                    'product_name' => $l['product']->name,
                    'variant_id' => $l['variant']?->id,
                    'variant_name' => $l['variant']?->name,
                    'quantity' => $l['qty'],
                    'unit_price' => $l['cost'],
                    'total_price' => round($l['qty'] * $l['cost'], 2),
                    'unit_cost' => $l['cost'],
                    'reason' => mb_substr($l['reason'], 0, 255),
                ]);
                $this->stock->change($companyId, $l['product']->id, $l['variant']?->id, $locationId, -$l['qty'], [
                    'type' => 'return',
                    'reference_type' => 'vendor_return',
                    'reference_id' => $return->id,
                    'reason' => 'Supplier return',
                    'notes' => $number,
                    'unit_cost' => $l['cost'],
                ]);
            }
        });

        return $this->getFull($returnId, $companyId);
    }

    public function update(int $id, int $companyId, array $data): VendorReturnDTO
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Vendor return not found');
        }

        $dbData = $this->mapInputToDb($data);

        // Auto-set completed_date when status becomes completed
        if (($dbData['status'] ?? null) === 'credited' && $return->status !== 'credited') {
            $dbData['completed_date'] = now();
            $dbData['credited_at'] = now();
        }

        $this->repository->update($id, $dbData);

        return $this->get($id, $companyId);
    }

    public function updateStatus(int $id, int $companyId, array $data): array
    {
        DB::transaction(function () use ($id, $companyId, $data) {
            $return = VendorReturn::where('company_id', $companyId)->lockForUpdate()->find($id);
            if (!$return) {
                throw new HttpException(404, 'Vendor return not found');
            }

            $current = self::normalizeStatus($return->status);
            $target = self::normalizeStatus($data['status'] ?? null);
            $idx = array_search($current, self::FLOW, true);
            if ($idx === false || !in_array($target, self::FLOW, true) || ($idx + 1) >= count(self::FLOW) || self::FLOW[$idx + 1] !== $target) {
                $next = $idx !== false && isset(self::FLOW[$idx + 1]) ? self::FLOW[$idx + 1] : null;
                throw new HttpException(422, $next
                    ? "A {$current} return can only move to {$next}"
                    : "A {$current} return cannot change status");
            }

            $update = ['status' => $target];
            if ($target === 'shipped') {
                $update['shipped_at'] = now();
            } elseif ($target === 'received') {
                $update['received_at'] = now();
            } else { // credited
                $update['credited_at'] = now();
                $update['completed_date'] = now();
            }
            $return->fill($update)->save();

            if ($target === 'credited' && self::normalizeCredit($return->credit_type) === 'note' && !$return->credit_applied && (float) $return->total_amount > 0) {
                $this->vendorService->applyCredit(
                    (int) $return->vendor_id,
                    $companyId,
                    (float) $return->total_amount,
                    'Credit note',
                    'Credit note for supplier return ' . $return->return_number,
                    \App\Models\User::whereKey(\Illuminate\Support\Facades\Auth::id())->exists() ? \Illuminate\Support\Facades\Auth::id() : null,
                );
                $return->credit_applied = true;
                $return->save();
            }
        });

        return $this->getFull($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Vendor return not found');
        }

        $this->repository->delete($id);
    }

    public function getStats(int $companyId): array
    {
        return $this->repository->getStats($companyId);
    }

    private function mapInputToDb(array $data): array
    {
        $dbData = [];

        if (isset($data['returnNumber'])) {
            $dbData['return_number'] = $data['returnNumber'];
        }
        if (isset($data['vendorId'])) {
            $dbData['vendor_id'] = $data['vendorId'];
        }
        if (isset($data['vendorName'])) {
            $dbData['vendor_name'] = $data['vendorName'];
        }
        if (isset($data['totalAmount'])) {
            $dbData['total_amount'] = $data['totalAmount'];
        }
        if (isset($data['status'])) {
            $dbData['status'] = self::normalizeStatus($data['status']);
        }
        if (isset($data['returnDate'])) {
            $dbData['return_date'] = $data['returnDate'];
        }
        if (isset($data['creditType'])) {
            $dbData['credit_type'] = self::normalizeCredit($data['creditType']);
        }
        if (isset($data['notes'])) {
            $dbData['notes'] = $data['notes'];
        }
        if (isset($data['createdBy'])) {
            $dbData['created_by'] = $data['createdBy'];
        }

        return $dbData;
    }
}
