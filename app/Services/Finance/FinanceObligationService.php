<?php

namespace App\Services\Finance;

use App\Services\Due\DueGroupService;
use App\Services\Tailor\TailorPaymentLedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What is owed to us (receivables) and what we owe (payables), plus the payouts / payments lists.
 * Customer & supplier dues come from DueGroupService, dorji owed from TailorPaymentLedgerService.
 */
class FinanceObligationService
{
    public const ROW_CAP = 50;
    private const COURIER_STATUSES = ['picked_up', 'in_transit', 'out_for_delivery', 'delivered'];
    private const PAYOUT_TYPES = ['supplier' => 'supplier_payment', 'salary' => 'salary', 'dorji' => 'dorji_payout'];
    private const PAYOUT_LABELS = ['supplier' => 'Supplier payment', 'salary' => 'Salary', 'dorji' => 'Dorji payout'];
    private const PAYMENT_TYPES = ['sale', 'cod', 'tailor_payment', 'supplier_refund', 'refund'];

    public function __construct(
        private readonly DueGroupService $dues,
        private readonly TailorPaymentLedgerService $tailorLedger,
        private readonly FinanceLedgerService $ledger,
        private readonly FinanceAccountService $accounts,
    ) {
    }

    /** Pass-through of DueGroupService::summary (rows + aging + stats) with camelCase meta. */
    public function dueSummary(string $kind, int $cid, array $filters): array
    {
        return $this->dues->summary($kind, $cid, $filters);
    }

    public function receivables(int $cid, array $in): array
    {
        $perPage = max(1, min(100, (int) ($in['perPage'] ?? $in['per_page'] ?? 25)));
        $res = $this->dues->summary('customer', $cid, [
            'search' => $in['search'] ?? null,
            'bucket' => $in['bucket'] ?? null,
            'payment' => $in['payment'] ?? 'all',
            'sort' => $in['sort'] ?? 'age',
            'per_page' => $perPage,
            'page' => $in['page'] ?? 1,
        ]);
        $m = $res['meta'];

        return [
            'data' => $res['data'] + ['context' => FinanceContext::context($cid, null, 'all', true)],
            'meta' => ['total' => $m['total'], 'perPage' => $m['per_page'], 'currentPage' => $m['current_page'], 'lastPage' => $m['last_page']],
        ];
    }

    // ─── Receivables ────────────────────────────────────────────────────────

    /** COD money held by couriers: COD shipments handed over whose cash is not recorded yet, capped at the order due. */
    public function codWithCouriers(int $cid, int $limit = self::ROW_CAP): array
    {
        $ships = DB::table('order_shipments as os')
            ->join('sells as s', 's.id', '=', 'os.sell_id')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->where('os.company_id', $cid)
            ->whereNull('s.deleted_at')->where('s.status', '!=', 'Cancelled')
            ->where('os.cod_collected', false)
            ->whereIn('os.status', self::COURIER_STATUSES)
            ->where('s.due_amount', '>', 0)
            ->where(fn ($q) => $q->where('os.cod_amount', '>', 0)->orWhereRaw("LOWER(s.method) = 'cod'"))
            ->orderBy('os.shipped_at')->orderBy('os.id')
            ->get(['os.id', 'os.sell_id', 's.invoice_no', DB::raw('COALESCE(c.name, s.customer_name) AS customer_name'), 'os.carrier', 'os.tracking_number',
                'os.status', 'os.cod_amount', 's.due_amount', 'os.shipped_at', 'os.delivered_at', 'os.created_at']);

        $left = [];
        $rows = [];
        $byCourier = [];
        $total = 0.0;
        $today = now()->startOfDay();
        foreach ($ships as $sh) {
            $left[$sh->sell_id] ??= (float) $sh->due_amount;
            $cod = (float) $sh->cod_amount;
            $amt = round(min($cod > 0 ? $cod : $left[$sh->sell_id], $left[$sh->sell_id]), 2);
            if ($amt <= 0) {
                continue;
            }
            $left[$sh->sell_id] -= $amt;
            $total += $amt;
            $carrier = $sh->carrier ?: 'Unknown';
            $byCourier[$carrier] ??= ['carrier' => $carrier, 'amount' => 0.0, 'count' => 0];
            $byCourier[$carrier]['amount'] += $amt;
            $byCourier[$carrier]['count']++;
            $since = $sh->shipped_at ?? $sh->created_at;
            $rows[] = [
                'shipmentId' => (int) $sh->id,
                'sellId' => (int) $sh->sell_id,
                'invoiceNo' => $sh->invoice_no,
                'customerName' => $sh->customer_name,
                'carrier' => $sh->carrier,
                'trackingNumber' => $sh->tracking_number,
                'status' => $sh->status,
                'codState' => $sh->status === 'delivered' ? 'delivered_record_payment' : 'to_collect',
                'amount' => $amt,
                'shippedAt' => $sh->shipped_at ? Carbon::parse($sh->shipped_at)->toIso8601String() : null,
                'deliveredAt' => $sh->delivered_at ? Carbon::parse($sh->delivered_at)->toIso8601String() : null,
                'ageDays' => $since ? max(0, (int) Carbon::parse($since)->startOfDay()->diffInDays($today)) : 0,
            ];
        }
        $byCourier = array_values(array_map(fn ($c) => ['carrier' => $c['carrier'], 'amount' => round($c['amount'], 2), 'count' => $c['count']], $byCourier));
        usort($byCourier, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return [
            'total' => round($total, 2),
            'count' => count($rows),
            'deliveredAmount' => round(array_sum(array_map(fn ($r) => $r['codState'] === 'delivered_record_payment' ? $r['amount'] : 0, $rows)), 2),
            'deliveredCount' => count(array_filter($rows, fn ($r) => $r['codState'] === 'delivered_record_payment')),
            'byCourier' => $byCourier,
            'rows' => array_slice($rows, 0, $limit),
        ];
    }

    public function tailorBalances(int $cid, int $limit = self::ROW_CAP): array
    {
        $q = DB::table('tailor_orders as o')
            ->leftJoin('tailor_customers as tc', 'tc.id', '=', 'o.customer_id')
            ->where('o.company_id', $cid)->whereNull('o.deleted_at')
            ->where('o.order_status', '!=', 'cancelled')->where('o.due_amount', '>', 0);
        $t = (clone $q)->selectRaw('COALESCE(SUM(o.due_amount),0) AS due, COUNT(*) AS n')->first();
        $rows = $limit > 0 ? (clone $q)->orderBy('o.order_date')->orderBy('o.id')->limit($limit)
            ->get(['o.id', 'o.order_number', 'tc.name', 'tc.phone', 'o.order_date', 'o.delivery_date', 'o.order_status', 'o.total_amount', 'o.paid_amount', 'o.due_amount'])
            ->map(fn ($o) => [
                'orderId' => (int) $o->id, 'orderNumber' => $o->order_number, 'customerName' => $o->name, 'customerPhone' => $o->phone,
                'orderDate' => $o->order_date ? substr((string) $o->order_date, 0, 10) : null,
                'deliveryDate' => $o->delivery_date ? substr((string) $o->delivery_date, 0, 10) : null,
                'status' => $o->order_status, 'total' => round((float) $o->total_amount, 2), 'paid' => round((float) $o->paid_amount, 2), 'due' => round((float) $o->due_amount, 2),
            ])->all() : [];

        return ['total' => round((float) $t->due, 2), 'count' => (int) $t->n, 'rows' => $rows];
    }

    public function receivablesSummary(int $cid, int $limit = self::ROW_CAP): array
    {
        $cust = $this->dues->summary('customer', $cid, ['per_page' => 1]);
        $customerTotal = (float) $cust['data']['stats']['totalDue'];
        $cod = $this->codWithCouriers($cid, $limit);
        $tailor = $this->tailorBalances($cid, $limit);

        return [
            'context' => FinanceContext::context($cid, null, 'all', true),
            'total' => round($customerTotal + $tailor['total'], 2),
            'customerDues' => [
                'total' => round($customerTotal, 2),
                'excludingCod' => round(max(0, $customerTotal - $cod['total']), 2),
                'partyCount' => (int) $cust['data']['stats']['partyCount'],
                'overdue30Amount' => (float) $cust['data']['stats']['overdue30Amount'],
                'overdue30Parties' => (int) $cust['data']['stats']['overdue30Parties'],
                'aging' => $cust['data']['aging'],
            ],
            'codWithCouriers' => $cod,
            'tailorBalances' => $tailor,
        ];
    }

    // ─── Payables ───────────────────────────────────────────────────────────

    public function unpaidSalaries(int $cid, int $limit = self::ROW_CAP): array
    {
        $q = DB::table('salary_payments as sp')->join('staff as st', 'st.id', '=', 'sp.staff_id')
            ->where('st.company_id', $cid)->whereNull('sp.deleted_at')
            ->whereRaw("LOWER(sp.status) <> 'paid'")
            ->where('sp.month', '<=', now()->format('Y-m'))
            ->where('sp.amount', '>', 0);
        $t = (clone $q)->selectRaw('COALESCE(SUM(sp.amount),0) AS amt, COUNT(*) AS n, COALESCE(SUM(CASE WHEN sp.month < ? THEN sp.amount ELSE 0 END),0) AS past, COALESCE(SUM(CASE WHEN sp.month < ? THEN 1 ELSE 0 END),0) AS past_n', [now()->format('Y-m'), now()->format('Y-m')])->first();
        $rows = $limit > 0 ? (clone $q)->orderBy('sp.month')->orderBy('st.name')->limit($limit)
            ->get(['sp.id', 'sp.staff_id', 'st.name', 'sp.month', 'sp.amount', 'sp.payment_method', 'st.payment_method as staff_method'])
            ->map(fn ($r) => [
                'salaryPaymentId' => (int) $r->id, 'staffId' => (int) $r->staff_id, 'name' => $r->name, 'month' => $r->month,
                'monthLabel' => preg_match('/^\d{4}-\d{2}$/', (string) $r->month) ? Carbon::createFromFormat('!Y-m', $r->month)->format('M Y') : (string) $r->month,
                'net' => round((float) $r->amount, 2), 'method' => $r->payment_method ?: ($r->staff_method ?: 'Cash'),
            ])->all() : [];

        return ['total' => round((float) $t->amt, 2), 'count' => (int) $t->n, 'pastMonthsAmount' => round((float) $t->past, 2), 'pastMonthsCount' => (int) $t->past_n, 'rows' => $rows];
    }

    public function dorjiOwed(int $cid, int $limit = self::ROW_CAP): array
    {
        $owed = $this->tailorLedger->dorjiOwed($cid);
        arsort($owed);
        $names = $owed ? DB::table('tailor_dorjis')->whereIn('id', array_keys($owed))->get(['id', 'name', 'phone'])->keyBy('id') : collect();
        $rows = [];
        foreach (array_slice($owed, 0, $limit, true) as $id => $amt) {
            $d = $names[$id] ?? null;
            $rows[] = ['dorjiId' => (int) $id, 'name' => $d->name ?? 'Unknown dorji', 'phone' => $d->phone ?? null, 'owed' => round((float) $amt, 2)];
        }

        return ['total' => round(array_sum($owed), 2), 'count' => count($owed), 'rows' => $rows];
    }

    public function payablesSummary(int $cid, int $limit = self::ROW_CAP): array
    {
        $v = $this->dues->summary('vendor', $cid, ['per_page' => max(1, $limit), 'sort' => 'amount']);
        $st = $v['data']['stats'];
        $supplierRows = $limit > 0 ? array_map(fn ($r) => [
            'vendorId' => (int) $r['partyId'], 'name' => $r['name'], 'phone' => $r['phone'] ?? null, 'due' => (float) $r['due'],
            'docs' => (int) $r['docs'], 'oldestDate' => $r['oldestDate'], 'dueDate' => $r['dueDate'] ?? null, 'overdue' => (bool) ($r['overdue'] ?? false),
        ], $v['data']['rows']) : [];
        $sal = $this->unpaidSalaries($cid, $limit);
        $dorji = $this->dorjiOwed($cid, $limit);

        return [
            'context' => FinanceContext::context($cid, null, 'all', true),
            'total' => round((float) $st['totalDue'] + $sal['total'] + $dorji['total'], 2),
            'supplierDues' => [
                'total' => round((float) $st['totalDue'], 2),
                'partyCount' => (int) $st['partyCount'],
                'overdueAmount' => (float) $st['overdueAmount'],
                'overdueParties' => (int) $st['overdueParties'],
                'dueNext7Amount' => (float) $st['dueNext7Amount'],
                'rows' => $supplierRows,
            ],
            'unpaidSalaries' => $sal,
            'dorjiOwed' => $dorji,
        ];
    }

    // ─── Lists over the ledger ──────────────────────────────────────────────

    private function typeList(mixed $raw, array $allowed, string $what): array
    {
        if ($raw === null || $raw === '' || $raw === 'all') {
            return [];
        }
        $out = [];
        foreach (is_array($raw) ? $raw : explode(',', (string) $raw) as $t) {
            $t = strtolower(trim((string) $t));
            if ($t === '' || $t === 'all') {
                continue;
            }
            if (!in_array($t, $allowed, true)) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(422, "Unknown {$what} type: {$t}.");
            }
            $out[] = $t;
        }

        return array_values(array_unique($out));
    }

    public function payouts(int $cid, array $in, bool $csv = false): array
    {
        $period = FinanceContext::period($cid, $in, 'all_time');
        $types = $this->typeList($in['type'] ?? null, array_keys(self::PAYOUT_TYPES), 'payout');
        $sources = $types ? array_map(fn ($t) => self::PAYOUT_TYPES[$t], $types) : array_values(self::PAYOUT_TYPES);
        $map = $this->accounts->map($cid);
        $q = $this->ledger->applyFilters(
            $this->ledger->applyPeriod($this->ledger->base($cid), $period->fromDate(), $period->toDate())->where('l.direction', 'out'),
            $map,
            ['accountId' => $in['accountId'] ?? null, 'sourceTypes' => $sources, 'method' => $in['method'] ?? null, 'search' => $in['search'] ?? null]
        );
        $toRow = function ($r) use ($map) {
            $row = $this->ledger->mapRow($r, $map);
            $type = array_search($row['sourceType'], self::PAYOUT_TYPES, true) ?: 'supplier';

            return [
                'id' => $row['id'], 'type' => $type, 'typeLabel' => self::PAYOUT_LABELS[$type], 'date' => $row['date'],
                'payee' => $row['party'], 'payeeId' => $row['partyId'], 'amount' => $row['amount'],
                'accountId' => $row['accountId'], 'accountName' => $row['accountName'],
                'method' => $row['method'], 'methodKey' => $row['methodKey'], 'methodLabel' => $row['methodLabel'],
                'reference' => $row['reference'], 'note' => $row['note'], 'link' => $row['link'],
            ];
        };
        if ($csv) {
            return ['csv' => $this->ledger->ordered(clone $q)->limit(FinanceLedgerService::CSV_CAP)->get()->map($toRow)->all()];
        }

        $byType = ['supplier' => 0.0, 'salary' => 0.0, 'dorji' => 0.0];
        $count = 0;
        foreach ((clone $q)->groupBy('l.source_type')->selectRaw('l.source_type, SUM(l.amount) AS amt, COUNT(*) AS n')->get() as $r) {
            $t = array_search($r->source_type, self::PAYOUT_TYPES, true);
            if ($t) {
                $byType[$t] = round((float) $r->amt, 2);
            }
            $count += (int) $r->n;
        }
        $perPage = max(1, min(100, (int) ($in['perPage'] ?? $in['per_page'] ?? 25)));
        $page = max(1, (int) ($in['page'] ?? 1));
        $rows = $this->ledger->ordered(clone $q)->forPage($page, $perPage)->get()->map($toRow)->all();

        return [
            'data' => [
                'rows' => $rows,
                'totals' => ['total' => round(array_sum($byType), 2), 'count' => $count, 'byType' => $byType],
                'period' => $period->toArray(),
                'context' => FinanceContext::context($cid, $period),
            ],
            'meta' => $this->ledger->meta($count, $perPage, $page),
        ];
    }

    public function payments(int $cid, array $in, bool $csv = false): array
    {
        $period = FinanceContext::period($cid, $in, 'all_time');
        $channel = FinanceContext::channel($in);
        $types = $this->typeList($in['type'] ?? null, self::PAYMENT_TYPES, 'payment');
        $map = $this->accounts->map($cid);
        $q = $this->ledger->applyFilters(
            $this->ledger->applyPeriod($this->ledger->base($cid), $period->fromDate(), $period->toDate()),
            $map,
            ['accountId' => $in['accountId'] ?? null, 'sourceTypes' => $types ?: self::PAYMENT_TYPES, 'method' => $in['method'] ?? null, 'search' => $in['search'] ?? null, 'channel' => $channel]
        );
        $toRow = fn ($r) => $this->ledger->mapRow($r, $map);
        if ($csv) {
            return ['csv' => $this->ledger->ordered(clone $q)->limit(FinanceLedgerService::CSV_CAP)->get()->map($toRow)->all()];
        }
        $t = $this->ledger->totals($q, $cid);
        $perPage = max(1, min(100, (int) ($in['perPage'] ?? $in['per_page'] ?? 25)));
        $page = max(1, (int) ($in['page'] ?? 1));

        return [
            'data' => [
                'rows' => $this->ledger->ordered(clone $q)->forPage($page, $perPage)->get()->map($toRow)->all(),
                'totals' => ['received' => $t['moneyIn'], 'refunded' => $t['moneyOut'], 'net' => $t['net'], 'count' => $t['count'], 'fees' => $t['fees'], 'netAfterFees' => $t['netAfterFees']],
                'period' => $period->toArray(),
                'channelScope' => 'sales_only',
                'context' => FinanceContext::context($cid, $period, $channel),
            ],
            'meta' => $this->ledger->meta($t['count'], $perPage, $page),
        ];
    }

    /** Sales with a balance, for the "Record payment" picker. */
    public function unpaidSales(int $cid, array $in): array
    {
        $limit = max(1, min(50, (int) ($in['limit'] ?? 20)));
        $s = trim((string) ($in['search'] ?? ''));

        return DB::table('sells as s')->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.company_id', $cid)->whereNull('s.deleted_at')->where('s.status', '!=', 'Cancelled')
            ->where('s.due_amount', '>', 0)
            ->when($s !== '', function ($q) use ($s) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
                $q->where(fn ($w) => $w->where('s.invoice_no', 'like', $like)->orWhere('s.customer_name', 'like', $like)
                    ->orWhere('c.name', 'like', $like)->orWhere('c.phone', 'like', $like)->orWhere('s.shipping_phone', 'like', $like));
            })
            ->orderByDesc('s.order_time')->orderByDesc('s.id')->limit($limit)
            ->get(['s.id', 's.invoice_no', DB::raw('COALESCE(c.name, s.customer_name) AS customer_name'), DB::raw('COALESCE(c.phone, s.shipping_phone) AS phone'),
                's.order_time', 's.amount', 's.paid_amount', 's.due_amount', 's.method', 's.status'])
            ->map(fn ($r) => [
                'sellId' => (int) $r->id, 'invoiceNo' => $r->invoice_no, 'customerName' => $r->customer_name, 'customerPhone' => $r->phone,
                'orderDate' => $r->order_time ? substr((string) $r->order_time, 0, 10) : null,
                'total' => round((float) $r->amount, 2), 'paid' => round((float) $r->paid_amount, 2), 'due' => round((float) $r->due_amount, 2),
                'method' => $r->method, 'status' => $r->status,
            ])->all();
    }
}
