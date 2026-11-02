<?php

namespace App\Services\Finance;

use App\Services\Report\ReportService;
use App\Support\ActorName;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Stock ↔ movements: opening-stock move-in (INV-1), backfill of sale movements for historic sale lines
 * (INV-2) and the stock value vs movement value reconciliation with line-by-line explanations (INV-3).
 * Product level, bundles and deleted products excluded (same as the stock valuation).
 */
class FinanceStockService
{
    public const OPENING_REF = 'opening_stock';
    public const BACKFILL_REASON = 'Sale (backfilled)';
    private const MOVE_COST = 'COALESCE(m.unit_cost, NULLIF(v.cost_price, 0), p.cost_price, 0)';

    public function __construct(
        private readonly ReportService $reports,
        private readonly FinanceMetricsService $metrics,
    ) {
    }

    // ─── Per-product figures ────────────────────────────────────────────────

    /** @return array<int, array{q: float, v: float}> on-hand units and cost value per product (valuation rules) */
    private function stockByProduct(int $cid): array
    {
        $rows = $this->reports->stockRowsSql($cid);
        $out = [];
        foreach (DB::select("SELECT inv.pid, SUM(CASE WHEN inv.qty > 0 THEN inv.qty ELSE 0 END) AS q, SUM(CASE WHEN inv.qty > 0 THEN inv.qty * inv.cost ELSE 0 END) AS v FROM ({$rows}) inv GROUP BY inv.pid") as $r) {
            $out[(int) $r->pid] = ['q' => (float) $r->q, 'v' => (float) $r->v];
        }

        return $out;
    }

    /** @return array<int, array{q: float, v: float, opening: bool}> movement units / value per product */
    private function movementsByProduct(int $cid): array
    {
        $uc = self::MOVE_COST;
        $out = [];
        foreach (DB::table('inventory_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'm.variant_id')
            ->where('m.company_id', $cid)->where('p.is_bundle', 0)->whereNull('p.deleted_at')
            ->groupBy('m.product_id')
            ->selectRaw("m.product_id, SUM(m.quantity) AS q, SUM(m.quantity * {$uc}) AS v, MAX(CASE WHEN m.reference_type = ? THEN 1 ELSE 0 END) AS o", [self::OPENING_REF])
            ->get() as $r) {
            $out[(int) $r->product_id] = ['q' => (float) $r->q, 'v' => (float) $r->v, 'opening' => (bool) $r->o];
        }

        return $out;
    }

    /** Current unit cost of a product: product cost, else the highest variant cost. */
    private function productCosts(int $cid, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (DB::table('products as p')->where('p.company_id', $cid)->whereIn('p.id', $ids)
            ->selectRaw('p.id, p.name, p.sku, p.location_id, p.cost_price, (SELECT MAX(v.cost_price) FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL) AS vc')
            ->get() as $r) {
            $c = (float) $r->cost_price > 0 ? (float) $r->cost_price : (float) $r->vc;
            $out[(int) $r->id] = ['name' => $r->name, 'sku' => $r->sku, 'locationId' => $r->location_id !== null ? (int) $r->location_id : null, 'cost' => round($c, 2)];
        }

        return $out;
    }

    // ─── INV-2: sale lines without a movement ───────────────────────────────

    /**
     * Sale lines (non-cancelled, stock deducted) with no `sell` movement for the same sale + product (or the same
     * order item), expanded to bundle components. Each item: what the backfill would insert.
     */
    public function missingSaleLines(int $cid): array
    {
        $lines = DB::select("
            SELECT oi.id AS item_id, oi.sell_id, oi.product_id, oi.variant_id, oi.quantity, oi.unit_cost, s.invoice_no, s.order_time, s.location_id, p.is_bundle, p.name
              FROM order_items oi
              JOIN sells s ON s.id = oi.sell_id
              JOIN products p ON p.id = oi.product_id
             WHERE s.company_id = ? AND s.deleted_at IS NULL AND s.status <> 'Cancelled' AND s.stock_deducted = 1 AND oi.quantity > 0
               AND NOT EXISTS (SELECT 1 FROM inventory_movements m WHERE m.company_id = s.company_id AND m.order_item_id = oi.id)
               AND NOT EXISTS (SELECT 1 FROM inventory_movements m WHERE m.company_id = s.company_id AND m.reference_type = 'sell' AND m.reference_id = s.id
                     AND (m.product_id = oi.product_id OR (p.is_bundle = 1 AND m.product_id IN (SELECT bi.product_id FROM product_bundle_items bi WHERE bi.bundle_product_id = p.id))))
             ORDER BY s.order_time, oi.id", [$cid]);

        $bundleIds = array_values(array_unique(array_map(fn ($l) => (int) $l->product_id, array_filter($lines, fn ($l) => (int) $l->is_bundle === 1))));
        $parts = [];
        if ($bundleIds) {
            foreach (DB::table('product_bundle_items as bi')->join('products as p', 'p.id', '=', 'bi.product_id')
                ->leftJoin('product_variants as v', 'v.id', '=', 'bi.variant_id')
                ->whereIn('bi.bundle_product_id', $bundleIds)
                ->get(['bi.bundle_product_id', 'bi.product_id', 'bi.variant_id', 'bi.quantity', 'p.name', DB::raw('COALESCE(NULLIF(v.cost_price, 0), p.cost_price, 0) AS cost')]) as $b) {
                $parts[(int) $b->bundle_product_id][] = $b;
            }
        }

        $out = [];
        foreach ($lines as $l) {
            $base = ['orderItemId' => (int) $l->item_id, 'sellId' => (int) $l->sell_id, 'invoiceNo' => $l->invoice_no, 'orderTime' => $l->order_time,
                'locationId' => $l->location_id !== null ? (int) $l->location_id : null];
            if ((int) $l->is_bundle === 1) {
                foreach ($parts[(int) $l->product_id] ?? [] as $b) {
                    $q = (int) $l->quantity * (int) $b->quantity;
                    $out[] = $base + ['productId' => (int) $b->product_id, 'productName' => $b->name, 'variantId' => $b->variant_id !== null ? (int) $b->variant_id : null,
                        'quantity' => $q, 'unitCost' => round((float) $b->cost, 2), 'bundleId' => (int) $l->product_id];
                }
                continue;
            }
            $out[] = $base + ['productId' => (int) $l->product_id, 'productName' => $l->name, 'variantId' => $l->variant_id !== null ? (int) $l->variant_id : null,
                'quantity' => (int) $l->quantity, 'unitCost' => round((float) $l->unit_cost, 2), 'bundleId' => null];
        }

        return $out;
    }

    public function missingSummary(int $cid): array
    {
        $items = $this->missingSaleLines($cid);
        $sample = array_map(fn ($i) => [
            'sellId' => $i['sellId'], 'invoiceNo' => $i['invoiceNo'],
            'orderDate' => $i['orderTime'] ? substr((string) FinanceContext::iso($cid, $i['orderTime']), 0, 10) : null,
            'productId' => $i['productId'], 'productName' => $i['productName'], 'variantId' => $i['variantId'],
            'quantity' => $i['quantity'], 'unitCost' => $i['unitCost'], 'value' => round($i['quantity'] * $i['unitCost'], 2),
        ], array_slice($items, 0, 50));

        return [
            'context' => FinanceContext::context($cid, null, 'all', true),
            'lines' => count(array_unique(array_column($items, 'orderItemId'))),
            'movements' => count($items),
            'sales' => count(array_unique(array_column($items, 'sellId'))),
            'quantity' => (int) array_sum(array_column($items, 'quantity')),
            'value' => round(array_sum(array_map(fn ($i) => $i['quantity'] * $i['unitCost'], $items)), 2),
            'sample' => $sample,
        ];
    }

    /** Create the missing `sell` movements (stock on hand is not changed). Idempotent. */
    public function backfillSaleMovements(int $cid, bool $dryRun): array
    {
        $run = function () use ($cid, $dryRun) {
            $items = $this->missingSaleLines($cid);
            $res = [
                'dryRun' => $dryRun,
                'created' => 0,
                'lines' => count(array_unique(array_column($items, 'orderItemId'))),
                'sales' => count(array_unique(array_column($items, 'sellId'))),
                'quantity' => (int) array_sum(array_column($items, 'quantity')),
                'value' => round(array_sum(array_map(fn ($i) => $i['quantity'] * $i['unitCost'], $items)), 2),
                'skipped' => 0,
            ];
            if ($dryRun || !$items) {
                return $res;
            }
            $now = now();
            $uid = Auth::id();
            $rows = [];
            foreach ($items as $i) {
                $rows[] = [
                    'company_id' => $cid, 'product_id' => $i['productId'], 'variant_id' => $i['variantId'], 'location_id' => $i['locationId'],
                    'type' => 'sell', 'reference_type' => 'sell', 'reference_id' => $i['sellId'], 'order_item_id' => $i['orderItemId'],
                    'quantity' => -$i['quantity'], 'quantity_before' => null, 'quantity_after' => null,
                    'unit_cost' => $i['unitCost'] > 0 ? $i['unitCost'] : null,
                    'reason' => self::BACKFILL_REASON, 'notes' => $i['invoiceNo'] . ($i['bundleId'] ? ' (bundle component)' : ''),
                    'created_by' => is_numeric($uid) ? (int) $uid : null, 'created_by_name' => ActorName::current() ?? 'System backfill',
                    'created_at' => $i['orderTime'] ?? $now, 'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('inventory_movements')->insert($chunk);
            }
            $res['created'] = count($rows);
            FinancePeriodLock::log($cid, 'sale_movements', null, 'posted', ['created' => count($rows), 'lines' => $res['lines']]);

            return $res;
        };

        return $dryRun ? $run() : DB::transaction($run);
    }

    // ─── INV-1: opening stock ───────────────────────────────────────────────

    private function defaultCutover(int $cid): array
    {
        $closed = array_keys(FinancePeriodLock::closedMonths($cid));
        if ($closed) {
            sort($closed);
            $d = Carbon::createFromFormat('!Y-m', end($closed))->addMonthNoOverflow()->startOfMonth()->toDateString();

            return [$d, 'First day after the last closed month'];
        }
        $firstMove = DB::table('inventory_movements')->where('company_id', $cid)->min('created_at');
        $firstSale = DB::table('sells')->where('company_id', $cid)->whereNull('deleted_at')->min('order_time');
        $first = array_filter([$firstMove, $firstSale]);
        if ($first) {
            $d = substr((string) FinanceContext::iso($cid, min($first)), 0, 10);

            return [$d, 'Date of the first stock movement or sale'];
        }

        return [FinanceContext::todayDate($cid), 'Today'];
    }

    private function cutover(int $cid, mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return $this->defaultCutover($cid);
        }
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) || Carbon::createFromFormat('!Y-m-d', $raw)->format('Y-m-d') !== $raw) {
            throw new HttpException(422, 'Cutover date must be a valid date (YYYY-MM-DD).');
        }
        if ($raw > FinanceContext::todayDate($cid)) {
            throw new HttpException(422, 'Cutover date cannot be in the future.');
        }
        FinancePeriodLock::assertOpen($cid, $raw, 'The cutover date is in a closed month (:month). Pick a date in an open month.');

        return [$raw, 'Chosen date'];
    }

    /** Products whose on-hand stock is not explained by movements (+ sale lines still to be backfilled). */
    public function openingCandidates(int $cid): array
    {
        $stock = $this->stockByProduct($cid);
        $moves = $this->movementsByProduct($cid);
        $missing = [];
        foreach ($this->missingSaleLines($cid) as $i) {
            $missing[$i['productId']] = ($missing[$i['productId']] ?? 0) + $i['quantity'];
        }
        $ids = array_keys($stock);
        $costs = $this->productCosts($cid, $ids);
        $rows = [];
        $already = 0;
        foreach ($stock as $pid => $st) {
            if (!isset($costs[$pid])) {
                continue;
            }
            $mv = $moves[$pid] ?? ['q' => 0.0, 'v' => 0.0, 'opening' => false];
            if ($mv['opening']) {
                $already++;
                continue;
            }
            $qty = (int) round($st['q'] - $mv['q'] + ($missing[$pid] ?? 0));
            if ($qty <= 0) {
                continue;
            }
            $unit = $st['q'] > 0 && $st['v'] > 0 ? round($st['v'] / $st['q'], 2) : $costs[$pid]['cost'];
            $rows[] = [
                'productId' => $pid, 'name' => $costs[$pid]['name'], 'sku' => $costs[$pid]['sku'],
                'quantity' => $qty, 'unitCost' => $unit, 'value' => round($qty * $unit, 2), 'needsCost' => $unit <= 0,
                'locationId' => $costs[$pid]['locationId'],
            ];
        }
        usort($rows, fn ($a, $b) => [$a['needsCost'], -$a['value']] <=> [$b['needsCost'], -$b['value']]);

        return ['rows' => $rows, 'alreadyMovedIn' => $already];
    }

    public function openingPreview(int $cid, array $in): array
    {
        [$cutover, $why] = $this->cutover($cid, $in['cutoverDate'] ?? null);
        $c = $this->openingCandidates($cid);
        $rows = $c['rows'];
        $s = mb_strtolower(trim((string) ($in['search'] ?? '')));
        $shown = $s === '' ? $rows : array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['name'] . ' ' . $r['sku']), $s)));
        $perPage = max(1, min(500, (int) ($in['perPage'] ?? $in['per_page'] ?? 100)));
        $page = max(1, (int) ($in['page'] ?? 1));
        $ready = array_filter($rows, fn ($r) => !$r['needsCost']);

        return [
            'data' => [
                'context' => FinanceContext::context($cid, null, 'all', true),
                'cutoverDate' => $cutover,
                'cutoverReason' => $why,
                'rows' => array_slice($shown, ($page - 1) * $perPage, $perPage),
                'totals' => [
                    'products' => count($rows), 'ready' => count($ready), 'needsCost' => count($rows) - count($ready),
                    'quantity' => (int) array_sum(array_column($rows, 'quantity')), 'value' => round(array_sum(array_column($ready, 'value')), 2),
                ],
                'alreadyMovedIn' => $c['alreadyMovedIn'],
            ],
            'meta' => ['total' => count($shown), 'perPage' => $perPage, 'currentPage' => $page, 'lastPage' => max(1, (int) ceil(count($shown) / $perPage))],
        ];
    }

    public function postOpening(int $cid, array $in, ?int $userId): array
    {
        [$cutover] = $this->cutover($cid, $in['cutoverDate'] ?? null);
        $overrides = [];
        foreach ((array) ($in['items'] ?? []) as $it) {
            if (!isset($it['productId'])) {
                continue;
            }
            $uc = $it['unitCost'] ?? null;
            if ($uc !== null && (!is_numeric($uc) || (float) $uc < 0.01)) {
                throw new HttpException(422, 'Unit cost must be at least 0.01.');
            }
            if ($uc !== null) {
                $overrides[(int) $it['productId']] = round((float) $uc, 2);
            }
        }
        $only = isset($in['productIds']) && is_array($in['productIds']) ? array_map('intval', $in['productIds']) : null;
        $setCost = !array_key_exists('setProductCost', $in) || filter_var($in['setProductCost'], FILTER_VALIDATE_BOOLEAN);

        return DB::transaction(function () use ($cid, $cutover, $overrides, $only, $setCost, $userId) {
            // serialise concurrent posts for this company
            DB::table('products')->where('company_id', $cid)->orderBy('id')->lockForUpdate()->pluck('id');
            $cand = $this->openingCandidates($cid)['rows'];
            $byId = [];
            foreach ($cand as $r) {
                $byId[$r['productId']] = $r;
            }
            $skipped = [];
            $posted = 0;
            $qty = 0;
            $value = 0.0;
            $at = FinanceContext::utcStartOf($cid, $cutover);
            $targets = $only ?? array_keys($byId);
            foreach ($targets as $pid) {
                $r = $byId[$pid] ?? null;
                if (!$r) {
                    $has = DB::table('inventory_movements')->where('company_id', $cid)->where('product_id', $pid)->where('reference_type', self::OPENING_REF)->exists();
                    $name = DB::table('products')->where('company_id', $cid)->where('id', $pid)->value('name');
                    $skipped[] = ['productId' => $pid, 'name' => $name, 'reason' => $has ? 'already_moved_in' : 'nothing_to_move', 'label' => $has ? 'Already moved in' : 'Nothing to move in'];
                    continue;
                }
                $unit = $overrides[$pid] ?? $r['unitCost'];
                if ($unit <= 0) {
                    $skipped[] = ['productId' => $pid, 'name' => $r['name'], 'reason' => 'needs_cost', 'label' => 'Needs cost'];
                    continue;
                }
                DB::table('inventory_movements')->insert([
                    'company_id' => $cid, 'product_id' => $pid, 'variant_id' => null, 'location_id' => $r['locationId'],
                    'type' => 'receive', 'reference_type' => self::OPENING_REF, 'reference_id' => null,
                    'quantity' => $r['quantity'], 'quantity_before' => null, 'quantity_after' => null, 'unit_cost' => $unit,
                    'reason' => 'Opening stock', 'notes' => 'Moved in from Finance (cutover ' . $cutover . ')',
                    'created_by' => $userId, 'created_by_name' => ActorName::current(), 'created_at' => $at, 'updated_at' => now(),
                ]);
                if ($setCost && isset($overrides[$pid])) {
                    DB::table('products')->where('company_id', $cid)->where('id', $pid)
                        ->where(fn ($w) => $w->whereNull('cost_price')->orWhere('cost_price', '<=', 0))->update(['cost_price' => $unit, 'updated_at' => now()]);
                }
                $posted++;
                $qty += $r['quantity'];
                $value += $r['quantity'] * $unit;
            }
            if ($posted) {
                FinancePeriodLock::log($cid, 'opening_stock', null, 'posted', ['products' => $posted, 'quantity' => $qty, 'value' => round($value, 2), 'cutoverDate' => $cutover]);
            }
            $remaining = count(array_filter($this->openingCandidates($cid)['rows'], fn ($x) => true));

            return ['posted' => $posted, 'quantity' => $qty, 'value' => round($value, 2), 'cutoverDate' => $cutover, 'skipped' => $skipped, 'remaining' => $remaining];
        });
    }

    // ─── INV-3: reconciliation ──────────────────────────────────────────────

    public function reconciliation(int $cid, int $cap = 200): array
    {
        $stock = $this->stockByProduct($cid);
        $moves = $this->movementsByProduct($cid);
        $missingBy = [];
        $missingVal = 0.0;
        $missingLines = 0;
        foreach ($this->missingSaleLines($cid) as $i) {
            $missingBy[$i['productId']] = true;
            $missingVal += $i['quantity'] * $i['unitCost'];
            $missingLines++;
        }
        $ids = array_values(array_unique(array_merge(array_keys($stock), array_keys($moves))));
        $costs = $this->productCosts($cid, $ids);
        $labels = [
            'no_opening_stock' => 'Stock never moved in',
            'untracked_stock' => 'Stock not explained by movements',
            'movements_exceed_stock' => 'Movements exceed stock on hand',
            'cost_changed' => 'Cost changed since the stock moved',
        ];
        $lines = [];
        $sv = 0.0;
        $mvTot = 0.0;
        foreach ($ids as $pid) {
            if (!isset($costs[$pid])) {
                continue; // bundle / deleted / other company
            }
            $st = $stock[$pid] ?? ['q' => 0.0, 'v' => 0.0];
            $mv = $moves[$pid] ?? ['q' => 0.0, 'v' => 0.0, 'opening' => false];
            $sv += $st['v'];
            $mvTot += $mv['v'];
            $gap = round($st['v'] - $mv['v'], 2);
            if (abs($gap) < 0.005) {
                continue;
            }
            $qGap = $st['q'] - $mv['q'];
            $avg = $st['q'] > 0 && $st['v'] > 0 ? $st['v'] / $st['q'] : $costs[$pid]['cost'];
            $qVal = round($qGap * $avg, 2);
            $name = $costs[$pid]['name'];
            $n = fn (float $x) => rtrim(rtrim(number_format(abs($x), 2, '.', ''), '0'), '.');
            if (abs($qGap) >= 0.0001 && abs($qVal) >= 0.005) {
                if ($qGap > 0) {
                    $kind = $mv['opening'] ? 'untracked_stock' : 'no_opening_stock';
                    $expl = $n($qGap) . ' on hand with no movement that brought them in.';
                    $action = $mv['opening']
                        ? ['type' => 'adjust', 'label' => 'Create adjustment', 'href' => '/dashboard/inventory/adjustment?productId=' . $pid]
                        : ['type' => 'move_in_opening_stock', 'label' => 'Move in opening stock', 'href' => '/dashboard/finance/inventory?tab=opening'];
                } else {
                    $kind = 'movements_exceed_stock';
                    $expl = 'Movements add up to ' . $n($qGap) . ' more than is on hand' . (isset($missingBy[$pid]) ? ' (some sales were never logged as stock movements).' : '.');
                    $action = isset($missingBy[$pid])
                        ? ['type' => 'backfill_sale_movements', 'label' => 'Post missing sale movements', 'href' => '/dashboard/finance/inventory?tab=history&backfill=1']
                        : ['type' => 'adjust', 'label' => 'Create adjustment', 'href' => '/dashboard/inventory/adjustment?productId=' . $pid];
                }
                $lines[] = ['kind' => $kind, 'label' => $labels[$kind], 'productId' => $pid, 'name' => $name, 'quantity' => round($qGap, 2), 'value' => $qVal, 'explanation' => $expl, 'action' => $action];
            } else {
                $qVal = 0.0;
            }
            $rem = round($gap - $qVal, 2);
            if (abs($rem) >= 0.005) {
                $lines[] = ['kind' => 'cost_changed', 'label' => $labels['cost_changed'], 'productId' => $pid, 'name' => $name, 'quantity' => null, 'value' => $rem,
                    'explanation' => 'Movements were valued at a different cost than today\'s cost (' . Money::format($cid, round($avg, 2)) . ').',
                    'action' => ['type' => 'set_cost', 'label' => 'Check cost', 'href' => '/dashboard/products/' . $pid]];
            }
        }
        $byKind = [];
        foreach ($lines as $l) {
            $byKind[$l['kind']] ??= ['kind' => $l['kind'], 'label' => $l['label'], 'count' => 0, 'value' => 0.0];
            $byKind[$l['kind']]['count']++;
            $byKind[$l['kind']]['value'] += $l['value'];
        }
        $byKind = array_values(array_map(fn ($k) => ['value' => round($k['value'], 2)] + $k, $byKind));
        $explained = round(array_sum(array_column($lines, 'value')), 2);
        usort($lines, fn ($a, $b) => abs($b['value']) <=> abs($a['value']));
        $diff = round($sv - $mvTot, 2);
        $m = Money::format($cid, abs($diff));

        return [
            'context' => FinanceContext::context($cid, null, 'all', true),
            'stockValue' => round($sv, 2),
            'movementValue' => round($mvTot, 2),
            'difference' => $diff,
            'status' => abs($diff) < 0.005 ? 'ok' : 'gap',
            'text' => abs($diff) < 0.005 ? 'Stock value matches the stock movements.'
                : ($diff > 0 ? "Stock value is {$m} higher than the stock movements explain." : "Stock value is {$m} lower than the stock movements explain."),
            'lines' => array_slice($lines, 0, $cap),
            'byKind' => $byKind,
            'missingSaleMovements' => ['lines' => $missingLines, 'value' => round($missingVal, 2)],
            'explained' => $explained,
            'unexplained' => round($diff - $explained, 2),
        ];
    }
}
