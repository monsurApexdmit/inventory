<?php

namespace App\Services\Finance;

use App\Services\Report\ReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Stock valuation (same storage model as the Stock report), inventory movement ledger and COGS. */
class FinanceInventoryService
{
    public const TYPES = ['receive' => 'Received', 'sell' => 'Sale', 'return' => 'Return', 'transfer' => 'Transfer', 'adjustment' => 'Adjustment', 'damage' => 'Damage'];
    private const VALUATION_SORTS = ['cost_desc' => 'cv DESC', 'retail_desc' => 'rv DESC', 'qty_desc' => 'q DESC', 'name_asc' => 'name ASC'];

    public function __construct(
        private readonly ReportService $reports,
        private readonly FinanceReportService $finance,
    ) {
    }

    private static function like(string $s): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
    }

    // ─── Valuation ──────────────────────────────────────────────────────────

    public function valuation(int $cid, array $in, bool $csv = false): array
    {
        $rows = $this->reports->stockRowsSql($cid);
        $where = ['inv.qty > 0'];
        $b = [];
        if (!empty($in['locationId'])) {
            $where[] = 'inv.loc = ?';
            $b[] = (int) $in['locationId'];
        }
        if (array_key_exists('categoryId', $in) && $in['categoryId'] !== null && $in['categoryId'] !== '') {
            if ($in['categoryId'] === 'none' || (int) $in['categoryId'] === 0) {
                $where[] = 'p.category_id IS NULL';
            } else {
                $where[] = 'p.category_id = ?';
                $b[] = (int) $in['categoryId'];
            }
        }
        $s = trim((string) ($in['search'] ?? ''));
        if ($s !== '') {
            $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
            $b[] = self::like($s);
            $b[] = self::like($s);
        }
        $base = "FROM ({$rows}) inv JOIN products p ON p.id = inv.pid LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN locations l ON l.id = inv.loc WHERE " . implode(' AND ', $where);

        $t = DB::selectOne("SELECT COUNT(DISTINCT inv.pid) AS n, COALESCE(SUM(inv.qty),0) AS units, COALESCE(SUM(inv.qty*inv.cost),0) AS cv, COALESCE(SUM(inv.qty*inv.price),0) AS rv {$base}", $b);
        $cv = (float) $t->cv;
        $rv = (float) $t->rv;
        $totals = [
            'productCount' => (int) $t->n,
            'units' => (int) $t->units,
            'costValue' => round($cv, 2),
            'retailValue' => round($rv, 2),
            'potentialProfit' => round($rv - $cv, 2),
            'marginPct' => $rv > 0 ? round(($rv - $cv) / $rv * 100, 2) : null,
        ];
        $group = fn (object $r) => [
            'units' => (int) $r->q, 'costValue' => round((float) $r->cv, 2), 'retailValue' => round((float) $r->rv, 2),
            'potentialProfit' => round((float) $r->rv - (float) $r->cv, 2),
        ];

        $sort = self::VALUATION_SORTS[$in['sort'] ?? 'cost_desc'] ?? self::VALUATION_SORTS['cost_desc'];
        $prodSql = "SELECT inv.pid, MAX(p.name) AS name, MAX(p.sku) AS sku, MAX(p.category_id) AS category_id, MAX(c.category_name) AS category_name,
                SUM(inv.qty) AS q, SUM(inv.qty*inv.cost) AS cv, SUM(inv.qty*inv.price) AS rv
            {$base} GROUP BY inv.pid ORDER BY {$sort}, inv.pid";
        $prodRow = fn (object $r) => [
            'productId' => (int) $r->pid, 'name' => $r->name, 'sku' => $r->sku,
            'categoryId' => $r->category_id !== null ? (int) $r->category_id : null, 'categoryName' => $r->category_name ?? 'Uncategorised',
            'unitCost' => (int) $r->q > 0 ? round((float) $r->cv / (int) $r->q, 2) : 0.0,
            'unitPrice' => (int) $r->q > 0 ? round((float) $r->rv / (int) $r->q, 2) : 0.0,
        ] + $group($r);

        if ($csv) {
            return ['csv' => array_map($prodRow, DB::select($prodSql . ' LIMIT ' . FinanceLedgerService::CSV_CAP, $b))];
        }

        $byLocation = array_map(fn ($r) => ['locationId' => $r->loc !== null ? (int) $r->loc : null, 'name' => $r->name ?? 'No location'] + $group($r),
            DB::select("SELECT inv.loc, MAX(l.name) AS name, SUM(inv.qty) AS q, SUM(inv.qty*inv.cost) AS cv, SUM(inv.qty*inv.price) AS rv
                {$base} GROUP BY inv.loc ORDER BY cv DESC", $b));
        $byCategory = array_map(fn ($r) => ['categoryId' => $r->category_id !== null ? (int) $r->category_id : null, 'name' => $r->name ?? 'Uncategorised'] + $group($r),
            DB::select("SELECT p.category_id, MAX(c.category_name) AS name, SUM(inv.qty) AS q, SUM(inv.qty*inv.cost) AS cv, SUM(inv.qty*inv.price) AS rv
                {$base} GROUP BY p.category_id ORDER BY cv DESC", $b));

        $perPage = max(1, min(100, (int) ($in['perPage'] ?? $in['per_page'] ?? 25)));
        $page = max(1, (int) ($in['page'] ?? 1));
        $rowsOut = array_map($prodRow, DB::select($prodSql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $b));

        return [
            'data' => ['totals' => $totals, 'byLocation' => $byLocation, 'byCategory' => $byCategory, 'rows' => $rowsOut, 'context' => FinanceContext::context($cid, null, 'all', true)],
            'meta' => ['total' => $totals['productCount'], 'perPage' => $perPage, 'currentPage' => $page, 'lastPage' => max(1, (int) ceil($totals['productCount'] / $perPage))],
        ];
    }

    // ─── Movement ledger ────────────────────────────────────────────────────

    private function movementQuery(int $cid, FinancePeriod $period, array $in)
    {
        $from = $period->fromDate();
        $to = $period->toDate();
        $q = DB::table('inventory_movements as m')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'm.variant_id')
            ->leftJoin('locations as loc', 'loc.id', '=', 'm.location_id')
            ->leftJoin('sells as rs', fn ($j) => $j->on('rs.id', '=', 'm.reference_id')->where('m.reference_type', '=', 'sell'))
            ->leftJoin('purchase_orders as rpo', fn ($j) => $j->on('rpo.id', '=', 'm.reference_id')->where('m.reference_type', '=', 'purchase_order'))
            ->leftJoin('customer_returns as rcr', fn ($j) => $j->on('rcr.id', '=', 'm.reference_id')->where('m.reference_type', '=', 'customer_return'))
            ->leftJoin('vendor_returns as rvr', fn ($j) => $j->on('rvr.id', '=', 'm.reference_id')->where('m.reference_type', '=', 'vendor_return'))
            ->leftJoin('tailor_orders as rto', fn ($j) => $j->on('rto.id', '=', 'm.reference_id')->where('m.reference_type', '=', 'tailor_order'))
            ->where('m.company_id', $cid);
        $this->finance->window($q, 'm.created_at', $from, $to, $cid);
        if (!empty($in['productId'])) {
            $q->where('m.product_id', (int) $in['productId']);
        }
        if (!empty($in['variantId'])) {
            $q->where('m.variant_id', (int) $in['variantId']);
        }
        if (!empty($in['locationId'])) {
            $q->where('m.location_id', (int) $in['locationId']);
        }
        if (!empty($in['type']) && $in['type'] !== 'all') {
            $types = array_values(array_filter(array_map('trim', explode(',', strtolower((string) $in['type'])))));
            foreach ($types as $t) {
                if (!isset(self::TYPES[$t]) && $t !== 'opening') {
                    throw new HttpException(422, "Unknown movement type: {$t}.");
                }
            }
            $plain = array_values(array_diff($types, ['opening']));
            $q->where(function ($w) use ($types, $plain) {
                if ($plain) {
                    $w->whereIn('m.type', $plain);
                }
                if (in_array('opening', $types, true)) {
                    $w->orWhere('m.reference_type', 'opening_stock');
                }
            });
        }
        if (!empty($in['direction'])) {
            if (!in_array($in['direction'], ['in', 'out'], true)) {
                throw new HttpException(422, 'Direction must be in or out.');
            }
            $q->where('m.quantity', $in['direction'] === 'in' ? '>' : '<', 0);
        }
        $s = trim((string) ($in['search'] ?? ''));
        if ($s !== '') {
            $like = self::like($s);
            $q->where(fn ($w) => $w->where('p.name', 'like', $like)->orWhere('p.sku', 'like', $like)->orWhere('v.sku', 'like', $like)
                ->orWhere('rs.invoice_no', 'like', $like)->orWhere('rpo.po_number', 'like', $like)->orWhere('rcr.return_number', 'like', $like)
                ->orWhere('rvr.return_number', 'like', $like)->orWhere('rto.order_number', 'like', $like)
                ->orWhere('m.reason', 'like', $like)->orWhere('m.notes', 'like', $like));
        }

        return $q;
    }

    private const UNIT_COST = 'COALESCE(m.unit_cost, NULLIF(v.cost_price, 0), p.cost_price, 0)';

    public function ledger(int $cid, array $in, bool $csv = false): array
    {
        $period = FinanceContext::period($cid, $in, 'this_month');
        $q = $this->movementQuery($cid, $period, $in);
        $uc = self::UNIT_COST;

        $productId = !empty($in['productId']) ? (int) $in['productId'] : null;
        $running = [];
        $product = null;
        if ($productId) {
            [$product, $running] = $this->runningQty($cid, $productId, !empty($in['variantId']) ? (int) $in['variantId'] : null, !empty($in['locationId']) ? (int) $in['locationId'] : null);
        }

        $select = ['m.id', 'm.created_at', 'm.product_id', 'p.name as product_name', DB::raw('COALESCE(v.sku, p.sku) AS sku'), 'm.variant_id', 'v.name as variant_name',
            'm.location_id', 'loc.name as location_name', 'm.type', 'm.quantity', 'm.quantity_before', 'm.quantity_after', DB::raw("{$uc} AS unit_cost"),
            'm.reference_type', 'm.reference_id', 'rs.invoice_no', 'rpo.po_number', 'rcr.return_number as cr_no', 'rvr.return_number as vr_no', 'rto.order_number as to_no',
            'm.reason', 'm.notes', 'm.created_by_name', 'm.order_item_id'];
        $order = fn ($x) => $x->orderByDesc('m.created_at')->orderByDesc('m.id');
        $map = fn ($r) => $this->movementRow($r, $running, $cid);

        if ($csv) {
            return ['csv' => $order(clone $q)->limit(FinanceLedgerService::CSV_CAP)->get($select)->map($map)->all()];
        }

        $t = (clone $q)->selectRaw("COUNT(*) AS n, COALESCE(SUM(CASE WHEN m.quantity > 0 THEN m.quantity ELSE 0 END),0) AS qi,
            COALESCE(SUM(CASE WHEN m.quantity < 0 THEN -m.quantity ELSE 0 END),0) AS qo,
            COALESCE(SUM(CASE WHEN m.quantity > 0 THEN m.quantity * {$uc} ELSE 0 END),0) AS vi,
            COALESCE(SUM(CASE WHEN m.quantity < 0 THEN -m.quantity * {$uc} ELSE 0 END),0) AS vo")->first();
        $perPage = max(1, min(100, (int) ($in['perPage'] ?? $in['per_page'] ?? 25)));
        $page = max(1, (int) ($in['page'] ?? 1));
        $rows = $order(clone $q)->forPage($page, $perPage)->get($select)->map($map)->all();
        $n = (int) $t->n;

        return [
            'data' => [
                'rows' => $rows,
                'totals' => ['count' => $n, 'qtyIn' => (int) $t->qi, 'qtyOut' => (int) $t->qo, 'valueIn' => round((float) $t->vi, 2), 'valueOut' => round((float) $t->vo, 2)],
                'product' => $product,
                'period' => $period->toArray(),
                'context' => FinanceContext::context($cid, $period),
            ],
            'meta' => ['total' => $n, 'perPage' => $perPage, 'currentPage' => $page, 'lastPage' => max(1, (int) ceil($n / $perPage))],
        ];
    }

    private function movementRow(object $r, array $running, ?int $cid = null): array
    {
        $isOpening = $r->reference_type === 'opening_stock';
        $qty = (int) $r->quantity;
        $uc = round((float) $r->unit_cost, 2);
        [$label, $href] = match ($r->reference_type) {
            'sell' => [$r->invoice_no ?: 'Sale #' . $r->reference_id, $r->invoice_no ? '/dashboard/sales?q=' . rawurlencode($r->invoice_no) : '/dashboard/sales'],
            'purchase_order' => [$r->po_number ?: 'PO #' . $r->reference_id, '/dashboard/vendors/purchase-orders'],
            'customer_return' => [$r->cr_no ?: 'Return #' . $r->reference_id, '/dashboard/returns/customer'],
            'vendor_return' => [$r->vr_no ?: 'Return #' . $r->reference_id, '/dashboard/returns/vendor'],
            'tailor_order' => [$r->to_no ?: 'Tailor order #' . $r->reference_id, $r->reference_id ? '/dashboard/tailor/orders/' . $r->reference_id : null],
            'manual' => ['ADJ-' . $r->id, '/dashboard/inventory/adjustment'],
            'opening_stock' => ['OPEN-' . $r->id, '/dashboard/finance/inventory?tab=opening'],
            null, '' => [$r->type === 'transfer' ? 'TR-' . $r->id : strtoupper(substr((string) $r->type, 0, 3)) . '-' . $r->id, $r->type === 'transfer' ? '/dashboard/inventory/transfer' : null],
            default => [strtoupper((string) $r->reference_type) . '-' . ($r->reference_id ?? $r->id), null],
        };

        return [
            'id' => (int) $r->id,
            'date' => $r->created_at ? ($cid ? FinanceContext::iso($cid, $r->created_at) : Carbon::parse($r->created_at)->toIso8601String()) : null,
            'productId' => (int) $r->product_id,
            'productName' => $r->product_name,
            'sku' => $r->sku,
            'variantId' => $r->variant_id !== null ? (int) $r->variant_id : null,
            'variantName' => $r->variant_name,
            'locationId' => $r->location_id !== null ? (int) $r->location_id : null,
            'locationName' => $r->location_name,
            'type' => $r->type,
            'typeLabel' => $isOpening ? 'Opening stock' : (self::TYPES[$r->type] ?? ucfirst((string) $r->type)),
            'quantity' => $qty,
            'qtyIn' => max(0, $qty),
            'qtyOut' => max(0, -$qty),
            'quantityBefore' => $r->quantity_before !== null ? (int) $r->quantity_before : null,
            'quantityAfter' => $r->quantity_after !== null ? (int) $r->quantity_after : null,
            'unitCost' => $uc,
            'value' => round(abs($qty) * $uc, 2),
            'reference' => ['type' => $r->reference_type ?: $r->type, 'id' => $r->reference_id !== null ? (int) $r->reference_id : null, 'label' => $label, 'href' => $href],
            'reason' => $r->reason,
            'notes' => $r->notes,
            'createdBy' => $r->created_by_name,
            'runningQty' => $running[$r->id] ?? null,
            'orderItemId' => isset($r->order_item_id) && $r->order_item_id !== null ? (int) $r->order_item_id : null,
            'backfilled' => $r->reason === 'Sale (backfilled)',
            'isOpening' => $isOpening,
            'ledgerHref' => $r->reference_type === 'sell' && $r->invoice_no ? '/dashboard/finance/transactions?search=' . rawurlencode($r->invoice_no) : null,
            'orderHref' => $r->reference_type === 'sell' && $r->invoice_no ? '/dashboard/orders?q=' . rawurlencode($r->invoice_no) : null,
        ];
    }

    /**
     * Today's stock of the product (variant / location) and, counted back from it, the stock right after each movement.
     *
     * @return array{0: array, 1: array<int, int>}
     */
    private function runningQty(int $cid, int $productId, ?int $variantId, ?int $locationId): array
    {
        $p = DB::table('products')->where('company_id', $cid)->where('id', $productId)->first(['id', 'name', 'sku', 'stock', 'location_id']);
        if (!$p) {
            throw new HttpException(404, 'Product not found');
        }
        if ($locationId) {
            $vq = DB::table('variant_inventory as vi')->join('product_variants as v', 'v.id', '=', 'vi.variant_id')
                ->where('v.product_id', $productId)->whereNull('v.deleted_at')->where('vi.location_id', $locationId)
                ->when($variantId, fn ($q) => $q->where('v.id', $variantId));
            $current = (int) $vq->sum('vi.quantity');
            if (!$variantId && !$vq->exists() && (int) $p->location_id === $locationId) {
                $current = (int) $p->stock;
            }
        } elseif ($variantId) {
            $current = (int) DB::table('product_variants')->where('id', $variantId)->where('product_id', $productId)->value('stock');
        } else {
            $current = (int) $p->stock;
        }

        $running = [];
        $after = $current;
        $mq = DB::table('inventory_movements')->where('company_id', $cid)->where('product_id', $productId)
            ->when($variantId, fn ($q) => $q->where('variant_id', $variantId))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->orderByDesc('created_at')->orderByDesc('id')->select(['id', 'quantity']);
        foreach ($mq->cursor() as $m) {
            $running[(int) $m->id] = $after;
            $after -= (int) $m->quantity;
        }

        return [['id' => (int) $p->id, 'name' => $p->name, 'sku' => $p->sku, 'currentQty' => $current], $running];
    }

    // ─── COGS ───────────────────────────────────────────────────────────────

    public function cogs(int $cid, array $in): array
    {
        $period = FinanceContext::period($cid, $in, 'this_month');
        $channel = FinanceContext::channel($in);
        $from = $period->fromDate();
        $to = $period->toDate();
        $loc = !empty($in['locationId']) ? (int) $in['locationId'] : null;

        $lines = function () use ($cid, $from, $to, $loc, $channel) {
            $q = DB::table('order_items')->join('sells', 'sells.id', '=', 'order_items.sell_id')
                ->where('sells.company_id', $cid)->whereNull('sells.deleted_at')->where('sells.status', '!=', 'Cancelled')
                ->when($loc, fn ($x) => $x->where('sells.location_id', $loc));
            FinanceContext::applyChannel($q, $channel, 'sells');

            return $this->finance->window($q, 'sells.order_time', $from, $to, $cid);
        };
        $sq = DB::table('sells')->where('company_id', $cid)->whereNull('deleted_at')->where('status', '!=', 'Cancelled')->when($loc, fn ($x) => $x->where('location_id', $loc));
        FinanceContext::applyChannel($sq, $channel, 'sells');
        $s = $this->finance->window($sq, 'sells.order_time', $from, $to, $cid)->selectRaw('COALESCE(SUM(amount),0) AS rev, COUNT(*) AS n')->first();
        $l = $lines()->selectRaw('COALESCE(SUM(order_items.total_cost),0) AS cogs, COALESCE(SUM(order_items.quantity),0) AS units')->first();
        $rev = (float) $s->rev;
        $cogs = (float) $l->cogs;

        $byCategory = $lines()->leftJoin('products as p', 'p.id', '=', 'order_items.product_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->groupBy('p.category_id')
            ->selectRaw("p.category_id, COALESCE(MAX(c.category_name), 'Uncategorised') AS name, SUM(order_items.quantity) AS units, SUM(order_items.total_price) AS rev, SUM(order_items.total_cost) AS cogs")
            ->orderByDesc('cogs')->get()
            ->map(fn ($r) => ['categoryId' => $r->category_id !== null ? (int) $r->category_id : null, 'name' => $r->name, 'units' => (int) $r->units,
                'revenue' => round((float) $r->rev, 2), 'cogs' => round((float) $r->cogs, 2), 'grossProfit' => round((float) $r->rev - (float) $r->cogs, 2)])->all();
        $top = $lines()->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, MAX(order_items.product_name) AS name, SUM(order_items.quantity) AS units, SUM(order_items.total_price) AS rev, SUM(order_items.total_cost) AS cogs')
            ->orderByDesc('cogs')->limit(20)->get()
            ->map(fn ($r) => ['productId' => $r->product_id !== null ? (int) $r->product_id : null, 'name' => $r->name, 'units' => (int) $r->units,
                'revenue' => round((float) $r->rev, 2), 'cogs' => round((float) $r->cogs, 2), 'grossProfit' => round((float) $r->rev - (float) $r->cogs, 2)])->all();

        return [
            'period' => $period->toArray(),
            'totals' => [
                'revenue' => round($rev, 2), 'cogs' => round($cogs, 2), 'grossProfit' => round($rev - $cogs, 2),
                'marginPct' => $rev ? round(($rev - $cogs) / $rev * 100, 2) : null, 'units' => (int) $l->units, 'orders' => (int) $s->n,
            ],
            'byCategory' => $byCategory,
            'topProducts' => $top,
            'context' => FinanceContext::context($cid, $period, $channel),
        ];
    }
}
