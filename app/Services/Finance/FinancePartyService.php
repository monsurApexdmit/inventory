<?php

namespace App\Services\Finance;

use App\Models\FinanceEntry;
use App\Models\PurchaseOrder;
use App\Support\ActorName;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Balances per party (PO-1/PO-2, RCV-1/2/4, PAY-5/6): what we owe each supplier / dorji / staff member,
 * broken down into owed → not yet due → credits → payable now; supplier credits ("they owe you") and
 * money received from suppliers; the customers receivables tab; the Payments page summary.
 */
class FinancePartyService
{
    private const PO_OPEN = ['draft', 'sent', 'partial', 'received'];
    private const PO_NOT_RECEIVED = ['draft', 'sent'];

    public function __construct(
        private readonly FinanceObligationService $obligations,
        private readonly FinanceMetricsService $metrics,
        private readonly FinanceAccountService $accounts,
    ) {
    }

    // ─── Suppliers ──────────────────────────────────────────────────────────

    /** Unapplied supplier credit per vendor: credited credit notes − credits applied to bills − money received back. */
    public function supplierCredits(int $cid): array
    {
        $issued = DB::table('vendor_returns')->where('company_id', $cid)->whereNull('deleted_at')->where('status', 'credited')
            ->where(fn ($w) => $w->whereNull('credit_type')->orWhereIn('credit_type', ['', 'note', 'credit_note']))
            ->where('credit_applied', 1)->whereNotNull('vendor_id')
            ->groupBy('vendor_id')->selectRaw('vendor_id, SUM(total_amount) AS a')->pluck('a', 'vendor_id')->all();
        $applied = DB::table('due_payments as dp')->join('purchase_orders as po', 'po.id', '=', 'dp.payable_id')
            ->where('dp.company_id', $cid)->where('dp.payable_type', PurchaseOrder::class)->where('dp.direction', 'out')
            ->whereRaw("LOWER(TRIM(COALESCE(dp.payment_method, ''))) = 'credit note'")
            ->groupBy('po.vendor_id')->selectRaw('po.vendor_id, SUM(dp.amount) AS a')->pluck('a', 'vendor_id')->all();
        $received = DB::table('finance_entries')->where('company_id', $cid)->where('kind', FinanceEntry::KIND_SUPPLIER_RECEIPT)->whereNotNull('vendor_id')
            ->groupBy('vendor_id')->selectRaw('vendor_id, SUM(amount) AS a')->pluck('a', 'vendor_id')->all();
        $out = [];
        foreach ($issued as $vid => $a) {
            $c = round((float) $a - (float) ($applied[$vid] ?? 0) - (float) ($received[$vid] ?? 0), 2);
            if ($c > 0.004) {
                $out[(int) $vid] = $c;
            }
        }

        return $out;
    }

    /** Supplier rows with owed / not yet due / credits / payable now (owed matches the v1 supplier dues). */
    public function suppliers(int $cid): array
    {
        $today = FinanceContext::todayDate($cid);
        $rows = [];
        $row = function (int $vid) use (&$rows) {
            return $rows[$vid] ??= ['owed' => 0.0, 'notYetDue' => 0.0, 'credits' => 0.0, 'nextDue' => null, 'docs' => 0, 'notReceived' => 0.0];
        };
        $pos = DB::table('purchase_orders as po')->join('vendors as v', 'v.id', '=', 'po.vendor_id')
            ->where('po.company_id', $cid)->whereIn('po.status', self::PO_OPEN)->where('po.due_amount', '>', 0)
            ->get(['po.vendor_id', 'po.status', 'po.due_amount', 'po.expected_date']);
        foreach ($pos as $p) {
            $vid = (int) $p->vendor_id;
            $row($vid);
            $due = (float) $p->due_amount;
            $rows[$vid]['owed'] += $due;
            $rows[$vid]['docs']++;
            $exp = $p->expected_date ? substr((string) $p->expected_date, 0, 10) : null;
            $notReceived = in_array($p->status, self::PO_NOT_RECEIVED, true);
            if ($notReceived || ($exp && $exp > $today)) {
                $rows[$vid]['notYetDue'] += $due;
                if ($notReceived) {
                    $rows[$vid]['notReceived'] += $due;
                }
                if ($exp && $exp > $today) {
                    $nd = &$rows[$vid]['nextDue'];
                    if ($nd === null || $exp < $nd['date']) {
                        $nd = ['date' => $exp, 'amount' => round($due, 2)];
                    } elseif ($exp === $nd['date']) {
                        $nd['amount'] = round($nd['amount'] + $due, 2);
                    }
                    unset($nd);
                }
            }
        }
        foreach (DB::table('vendors')->where('company_id', $cid)->whereNull('deleted_at')->where('opening_balance', '>', 0)->get(['id', 'opening_balance']) as $v) {
            $row((int) $v->id);
            $rows[(int) $v->id]['owed'] += (float) $v->opening_balance;
            $rows[(int) $v->id]['docs']++;
        }
        foreach ($this->supplierCredits($cid) as $vid => $c) {
            $row($vid);
            $rows[$vid]['credits'] = $c;
        }
        $names = $rows ? DB::table('vendors')->where('company_id', $cid)->whereIn('id', array_keys($rows))->get(['id', 'name', 'deleted_at'])->keyBy('id') : collect();

        $out = [];
        foreach ($rows as $vid => $r) {
            $v = $names[$vid] ?? null;
            $out[] = $this->partyRow('supplier', $vid, $v?->name, $v === null || $v->deleted_at !== null, $v === null, $r['owed'], $r['notYetDue'], $r['credits'], $r['nextDue'], $cid, $r['notReceived']);
        }

        return $out;
    }

    private static function tail(int $id): string
    {
        return '…' . str_pad(substr((string) $id, -4), 4, '0', STR_PAD_LEFT);
    }

    private function partyRow(string $type, int $id, ?string $name, bool $deleted, bool $missing, float $owed, float $notYetDue, float $credits, ?array $nextDue, int $cid, float $notReceived = 0.0): array
    {
        $owed = round($owed, 2);
        $notYetDue = round($notYetDue, 2);
        $credits = round($credits, 2);
        // credits first cover what is due now; the rest is kept for bills not yet due; beyond that the party owes us
        $held = round(min(max(0, $credits - ($owed - $notYetDue)), $notYetDue), 2);
        $payable = round($owed - $notYetDue - $credits + $held, 2);
        $what = ['supplier' => 'supplier', 'dorji' => 'dorji', 'staff' => 'staff member'][$type];
        $display = $missing || $name === null || $name === ''
            ? 'Deleted ' . $what . ' · ' . self::tail($id)
            : ($deleted ? $name . ' (deleted)' : $name);
        $status = $payable > 0.004 ? 'payable' : ($payable < -0.004 ? 'they_owe_you' : 'nothing_payable');
        $owedLabel = ['supplier' => 'Owed to supplier', 'dorji' => 'Owed to dorji', 'staff' => 'Unpaid salary'][$type];

        $row = [
            'type' => $type,
            'id' => $id,
            'name' => $name,
            'displayName' => $display,
            'deleted' => $deleted || $missing,
            'fullId' => (string) $id,
            'owed' => $owed,
            'notYetDue' => $notYetDue,
            'credits' => $credits,
            'creditsHeld' => $held,
            'payableNow' => $payable,
            'breakdown' => [
                ['key' => 'owed', 'label' => $owedLabel, 'amount' => $owed],
                ['key' => 'not_yet_due', 'label' => $type === 'staff' ? 'This month (not due yet)' : 'Not yet due', 'amount' => -$notYetDue],
                ['key' => 'credits', 'label' => 'Credits they owe you', 'amount' => -$credits],
                ['key' => 'credits_held', 'label' => 'Credits kept for bills not yet due', 'amount' => $held],
                ['key' => 'payable_now', 'label' => 'Payable now', 'amount' => $payable],
            ],
            'nextDue' => $nextDue,
            'status' => $status,
            'payHref' => $payable > 0.004 ? "/dashboard/finance/payouts?type={$type}&id={$id}&create=1" : null,
        ];
        if ($type === 'supplier') {
            $net = round($owed - $credits, 2);
            $row['net'] = $net;
            $row['direction'] = $net < -0.004 ? 'they_owe' : ($net > 0.004 ? 'we_owe' : 'settled');
            $row['directionLabel'] = ['they_owe' => 'They owe you', 'we_owe' => 'You owe', 'settled' => 'Settled'][$row['direction']];
            $row['action'] = $row['direction'] === 'they_owe' ? 'collect' : ($row['direction'] === 'we_owe' ? 'pay' : null);
            $row['payDisabledReason'] = null;
            if ($row['action'] === 'pay' && $payable <= 0.004) {
                $row['payDisabledReason'] = $nextDue
                    ? Money::format($cid, $nextDue['amount']) . ' becomes payable on ' . Carbon::parse($nextDue['date'])->format('j M Y')
                    : ($notReceived > 0 ? 'Nothing is payable yet: the purchase orders are not received.' : 'Nothing is payable yet.');
            }
            $row['collectHref'] = $row['action'] === 'collect' ? "/dashboard/finance/receivables?tab=suppliers&collect={$id}" : null;
            if ($row['action'] !== 'pay') {
                $row['payHref'] = null;
            }
        }

        return $row;
    }

    private function dorjis(int $cid): array
    {
        $d = $this->obligations->dorjiOwed($cid, 100000);
        $out = [];
        foreach ($d['rows'] as $r) {
            $missing = $r['name'] === 'Unknown dorji';
            $out[] = $this->partyRow('dorji', (int) $r['dorjiId'], $missing ? null : $r['name'], false, $missing, (float) $r['owed'], 0.0, 0.0, null, $cid);
        }

        return $out;
    }

    private function staff(int $cid): array
    {
        $cur = FinanceContext::today($cid)->format('Y-m');
        $by = [];
        foreach ($this->obligations->unpaidSalaries($cid, 100000)['rows'] as $r) {
            $sid = (int) $r['staffId'];
            $by[$sid] ??= ['name' => $r['name'], 'owed' => 0.0, 'cur' => 0.0];
            $by[$sid]['owed'] += (float) $r['net'];
            if ((string) $r['month'] >= $cur) {
                $by[$sid]['cur'] += (float) $r['net'];
            }
        }
        $out = [];
        foreach ($by as $sid => $b) {
            $next = $b['cur'] > 0 ? ['date' => Carbon::createFromFormat('!Y-m', $cur)->endOfMonth()->toDateString(), 'amount' => round($b['cur'], 2)] : null;
            $out[] = $this->partyRow('staff', $sid, $b['name'], false, false, $b['owed'], $b['cur'], 0.0, $next, $cid);
        }

        return $out;
    }

    private static function types(mixed $raw): array
    {
        $all = ['supplier', 'dorji', 'staff'];
        if ($raw === null || $raw === '' || $raw === 'all') {
            return $all;
        }
        $out = [];
        foreach (is_array($raw) ? $raw : explode(',', (string) $raw) as $t) {
            $t = strtolower(trim((string) $t));
            if ($t === '' || $t === 'all') {
                continue;
            }
            if (!in_array($t, $all, true)) {
                throw new HttpException(422, "Unknown payee type: {$t}.");
            }
            $out[] = $t;
        }

        return $out ?: $all;
    }

    private static function totals(array $rows): array
    {
        $t = ['owed' => 0.0, 'notYetDue' => 0.0, 'credits' => 0.0, 'payableNow' => 0.0, 'theyOweYou' => 0.0, 'parties' => count($rows)];
        foreach ($rows as $r) {
            $t['owed'] += $r['owed'];
            $t['notYetDue'] += $r['notYetDue'];
            $t['credits'] += $r['credits'];
            $t['payableNow'] += max(0, $r['payableNow']);
            $t['theyOweYou'] += max(0, $r['credits'] - $r['owed']);
        }

        return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $t);
    }

    /** GET /finance/payouts/payable */
    public function payable(int $cid, array $in): array
    {
        $types = self::types($in['type'] ?? null);
        $rows = [];
        if (in_array('supplier', $types, true)) {
            $rows = array_merge($rows, $this->suppliers($cid));
        }
        if (in_array('dorji', $types, true)) {
            $rows = array_merge($rows, $this->dorjis($cid));
        }
        if (in_array('staff', $types, true)) {
            $rows = array_merge($rows, $this->staff($cid));
        }
        $s = mb_strtolower(trim((string) ($in['search'] ?? '')));
        if ($s !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['displayName']), $s)));
        }
        usort($rows, fn ($a, $b) => [$b['payableNow'], $b['owed']] <=> [$a['payableNow'], $a['owed']]);
        $payable = array_values(array_filter($rows, fn ($r) => $r['payableNow'] > 0.004));
        $nothing = array_values(array_filter($rows, fn ($r) => $r['payableNow'] <= 0.004));

        return [
            'context' => FinanceContext::context($cid, null, 'all', true),
            'rows' => $rows,
            'groups' => ['payable' => $payable, 'nothingPayable' => $nothing],
            'totals' => self::totals($rows),
        ];
    }

    /** GET /finance/payouts/payable/{type}/{id} */
    public function payableOne(int $cid, string $type, int $id): array
    {
        $type = self::types($type)[0];
        $rows = match ($type) {
            'supplier' => $this->suppliers($cid),
            'dorji' => $this->dorjis($cid),
            default => $this->staff($cid),
        };
        $row = collect($rows)->firstWhere('id', $id);
        $label = ['supplier' => 'Supplier', 'dorji' => 'Dorji', 'staff' => 'Staff'][$type];
        $table = ['supplier' => 'vendors', 'dorji' => 'tailor_dorjis', 'staff' => 'staff'][$type];
        if (!$row) {
            $p = DB::table($table)->where('company_id', $cid)->where('id', $id)->first();
            if (!$p) {
                throw new HttpException(404, "{$label} not found");
            }
            $row = $this->partyRow($type, $id, $p->name ?? null, isset($p->deleted_at) && $p->deleted_at !== null, false, 0, 0, 0, null, $cid);
        }
        $today = FinanceContext::todayDate($cid);
        $docs = [];
        if ($type === 'supplier') {
            foreach (DB::table('purchase_orders')->where('company_id', $cid)->where('vendor_id', $id)->whereIn('status', self::PO_OPEN)->where('due_amount', '>', 0)
                ->orderBy('created_at')->get(['id', 'po_number', 'status', 'created_at', 'expected_date', 'due_amount']) as $p) {
                $exp = $p->expected_date ? substr((string) $p->expected_date, 0, 10) : null;
                $docs[] = ['documentType' => 'purchase_order', 'documentId' => (int) $p->id, 'no' => $p->po_number, 'status' => $p->status,
                    'date' => FinanceContext::iso($cid, $p->created_at) ? substr(FinanceContext::iso($cid, $p->created_at), 0, 10) : null, 'dueDate' => $exp,
                    'due' => round((float) $p->due_amount, 2), 'notYetDue' => in_array($p->status, self::PO_NOT_RECEIVED, true) || ($exp && $exp > $today)];
            }
            $ob = (float) DB::table('vendors')->where('company_id', $cid)->where('id', $id)->whereNull('deleted_at')->value('opening_balance');
            if ($ob > 0) {
                $docs[] = ['documentType' => 'opening_balance', 'documentId' => null, 'no' => 'Opening balance', 'status' => null, 'date' => null, 'dueDate' => null, 'due' => round($ob, 2), 'notYetDue' => false];
            }
        } elseif ($type === 'staff') {
            foreach ($this->obligations->unpaidSalaries($cid, 100000)['rows'] as $r) {
                if ((int) $r['staffId'] === $id) {
                    $docs[] = ['documentType' => 'payslip', 'documentId' => $r['salaryPaymentId'], 'salaryPaymentId' => $r['salaryPaymentId'],
                        'no' => 'Salary ' . $r['monthLabel'], 'month' => $r['month'], 'monthLabel' => $r['monthLabel'], 'method' => $r['method'],
                        'status' => 'unpaid', 'date' => null, 'dueDate' => Carbon::createFromFormat('!Y-m', $r['month'])->endOfMonth()->toDateString(),
                        'due' => $r['net'], 'notYetDue' => (string) $r['month'] >= FinanceContext::today($cid)->format('Y-m')];
                }
            }
        }

        return $row + ['documents' => $docs, 'context' => FinanceContext::context($cid, null, 'all', true)];
    }

    /** GET /finance/receivables/suppliers */
    public function supplierReceivables(int $cid, array $in): array
    {
        $rows = $this->suppliers($cid);
        $dir = $in['direction'] ?? 'all';
        if (in_array($dir, ['we_owe', 'they_owe'], true)) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['direction'] === $dir));
        }
        $s = mb_strtolower(trim((string) ($in['search'] ?? '')));
        if ($s !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['displayName']), $s)));
        }
        usort($rows, fn ($a, $b) => abs($b['net']) <=> abs($a['net']));
        $t = ['owed' => 0.0, 'credits' => 0.0, 'net' => 0.0, 'weOwe' => 0.0, 'theyOweYou' => 0.0, 'payableNow' => 0.0];
        foreach ($rows as $r) {
            $t['owed'] += $r['owed'];
            $t['credits'] += $r['credits'];
            $t['net'] += $r['net'];
            if ($r['net'] > 0) {
                $t['weOwe'] += $r['net'];
            } else {
                $t['theyOweYou'] += -$r['net'];
            }
            $t['payableNow'] += max(0, $r['payableNow']);
        }

        return ['context' => FinanceContext::context($cid, null, 'all', true), 'rows' => $rows, 'totals' => array_map(fn ($v) => round($v, 2), $t)];
    }

    /** Totals block for /receivables/summary and the overview. */
    public function supplierSummary(int $cid): array
    {
        $t = $this->supplierReceivables($cid, [])['totals'];

        return ['owed' => $t['owed'], 'credits' => $t['credits'], 'theyOweYou' => $t['theyOweYou'], 'net' => $t['net']];
    }

    /** POST /finance/receivables/suppliers/{vendorId}/receive */
    public function receiveFromSupplier(int $cid, int $vendorId, array $d, ?int $userId): array
    {
        $v = DB::table('vendors')->where('company_id', $cid)->where('id', $vendorId)->first(['id', 'name']);
        $credits = $this->supplierCredits($cid);
        if (!$v && !isset($credits[$vendorId])) {
            throw new HttpException(404, 'Supplier not found');
        }
        $amount = $d['amount'] ?? null;
        if (!is_numeric($amount) || (float) $amount <= 0) {
            throw new HttpException(422, 'Amount must be greater than zero.');
        }
        $amount = round((float) $amount, 2);
        $row = collect($this->suppliers($cid))->firstWhere('id', $vendorId);
        $owesUs = $row ? round(max(0, $row['credits'] - $row['owed']), 2) : 0.0;
        if ($owesUs <= 0) {
            throw new HttpException(422, "This supplier doesn't owe you anything.");
        }
        if ($amount > $owesUs + 0.004) {
            throw new HttpException(422, 'Amount is more than the supplier owes you (' . Money::format($cid, $owesUs) . ').');
        }
        $date = $d['date'] ?? FinanceContext::todayDate($cid);
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new HttpException(422, 'Date must be a valid date (YYYY-MM-DD).');
        }
        if ($date > Carbon::parse(FinanceContext::todayDate($cid))->addDay()->toDateString()) {
            throw new HttpException(422, 'Date cannot be in the future.');
        }
        $map = $this->accounts->map($cid);
        $accountId = !empty($d['accountId']) ? (int) $d['accountId'] : $map->resolve(null, !empty($d['method']) ? FinanceMethods::key((string) $d['method']) : 'cash');
        if (!$accountId || !isset($map->accounts[$accountId])) {
            throw new HttpException(422, 'Account not found');
        }
        $e = FinanceEntry::create([
            'company_id' => $cid,
            'account_id' => $accountId,
            'vendor_id' => $vendorId,
            'kind' => FinanceEntry::KIND_SUPPLIER_RECEIPT,
            'direction' => 'in',
            'amount' => $amount,
            'date' => $date,
            'category' => 'Supplier credit',
            'party' => mb_substr((string) ($v->name ?? ('Supplier ' . self::tail($vendorId))), 0, 100),
            'reference' => isset($d['reference']) ? mb_substr((string) $d['reference'], 0, 100) : null,
            'note' => isset($d['note']) ? mb_substr((string) $d['note'], 0, 1000) : 'Payment received from supplier',
            'created_by' => $userId,
            'created_by_name' => ActorName::current(),
        ]);

        return (new \App\DTOs\Finance\FinanceEntryMapper())->toDTO($e->fresh('account'))->toArray();
    }

    // ─── Customers ──────────────────────────────────────────────────────────

    /** GET /finance/receivables/customers: one row per unpaid / partly paid sale. */
    public function customers(int $cid, array $in): array
    {
        $channel = FinanceContext::channel($in);
        $today = FinanceContext::today($cid);
        $q = DB::table('sells as s')->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.company_id', $cid)->whereNull('s.deleted_at')->where('s.status', '!=', 'Cancelled')->where('s.due_amount', '>', 0);
        FinanceContext::applyChannel($q, $channel, 's');
        $s = trim((string) ($in['search'] ?? ''));
        if ($s !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
            $q->where(fn ($w) => $w->where('s.invoice_no', 'like', $like)->orWhere('s.customer_name', 'like', $like)->orWhere('c.name', 'like', $like)
                ->orWhere('c.phone', 'like', $like)->orWhere('s.shipping_phone', 'like', $like));
        }
        $codSql = FinanceMethods::sqlKey('s.method') . " = 'cod'";
        if (isset($in['cod']) && $in['cod'] !== '' && $in['cod'] !== 'all') {
            $cod = filter_var($in['cod'], FILTER_VALIDATE_BOOLEAN);
            $q->whereRaw($cod ? "({$codSql})" : "NOT ({$codSql})");
        }
        $local = FinanceContext::localDateSql($cid, 'COALESCE(s.order_time, s.created_at)');
        $rows = $q->orderByRaw('COALESCE(s.order_time, s.created_at) ASC')->orderBy('s.id')
            ->get(['s.id', 's.invoice_no', 's.customer_id', DB::raw('COALESCE(c.name, s.customer_name) AS customer_name'), DB::raw('COALESCE(c.phone, s.shipping_phone) AS phone'),
                DB::raw("{$local} AS d"), 's.amount', 's.paid_amount', 's.due_amount', 's.method', 's.status', 's.source', DB::raw("({$codSql}) AS is_cod")]);

        $codState = [];
        if ($rows->isNotEmpty()) {
            foreach (DB::table('order_shipments')->whereIn('sell_id', $rows->pluck('id'))->where('cod_collected', false)
                ->whereIn('status', ['picked_up', 'in_transit', 'out_for_delivery', 'delivered'])->get(['sell_id', 'status']) as $sh) {
                $st = $sh->status === 'delivered' ? 'delivered_record_payment' : 'to_collect';
                if (($codState[$sh->sell_id] ?? null) !== 'delivered_record_payment') {
                    $codState[$sh->sell_id] = $st;
                }
            }
        }
        $bucketOf = fn (int $d) => $d <= 30 ? 0 : ($d <= 60 ? 1 : 2);
        $all = [];
        foreach ($rows as $r) {
            $age = $r->d ? max(0, (int) Carbon::parse($r->d)->diffInDays($today)) : 0;
            $all[] = [
                'sellId' => (int) $r->id, 'invoiceNo' => $r->invoice_no, 'customerId' => $r->customer_id !== null ? (int) $r->customer_id : null,
                'customerName' => $r->customer_name ?: ($r->customer_id ? 'Unknown' : 'Walk-in customer'), 'customerPhone' => $r->phone,
                'orderDate' => $r->d ? substr((string) $r->d, 0, 10) : null, 'ageDays' => $age, 'bucket' => $bucketOf($age),
                'total' => round((float) $r->amount, 2), 'paid' => round((float) $r->paid_amount, 2), 'due' => round((float) $r->due_amount, 2),
                'method' => $r->method, 'methodKey' => FinanceMethods::key($r->method), 'isCod' => (bool) $r->is_cod,
                'codState' => $codState[$r->id] ?? null,
                'channel' => $r->source === 'pos' ? 'pos' : (in_array($r->source, ['storefront', 'online'], true) ? 'online' : 'manual'),
                'status' => $r->status, 'orderHref' => '/dashboard/orders?q=' . rawurlencode((string) $r->invoice_no),
            ];
        }
        $labels = [0 => '0–30 days', 1 => '31–60 days', 2 => '60+ days'];
        $aging = [];
        foreach ($labels as $k => $l) {
            $in_ = array_filter($all, fn ($r) => $r['bucket'] === $k);
            $aging[] = ['key' => $k, 'label' => $l, 'amount' => round(array_sum(array_column($in_, 'due')), 2), 'count' => count($in_)];
        }
        $totals = [
            'due' => round(array_sum(array_column($all, 'due')), 2),
            'count' => count($all),
            'customers' => count(array_unique(array_map(fn ($r) => $r['customerId'] ?? ('w' . $r['customerName']), $all))),
            'codDue' => round(array_sum(array_map(fn ($r) => $r['isCod'] ? $r['due'] : 0, $all)), 2),
        ];
        $shown = $all;
        if (isset($in['bucket']) && $in['bucket'] !== '' && $in['bucket'] !== 'all') {
            $b = (int) $in['bucket'];
            $shown = array_values(array_filter($shown, fn ($r) => $r['bucket'] === $b));
        }
        $sort = $in['sort'] ?? 'age';
        if ($sort === 'amount') {
            usort($shown, fn ($a, $b) => $b['due'] <=> $a['due']);
        } elseif ($sort === 'name') {
            usort($shown, fn ($a, $b) => strcasecmp((string) $a['customerName'], (string) $b['customerName']));
        } else {
            usort($shown, fn ($a, $b) => $b['ageDays'] <=> $a['ageDays']);
        }
        $perPage = max(1, min(100, (int) ($in['perPage'] ?? $in['per_page'] ?? 25)));
        $page = max(1, (int) ($in['page'] ?? 1));

        return [
            'data' => [
                'context' => FinanceContext::context($cid, null, $channel, true),
                'rows' => array_slice($shown, ($page - 1) * $perPage, $perPage),
                'aging' => $aging,
                'totals' => $totals,
            ],
            'meta' => ['total' => count($shown), 'perPage' => $perPage, 'currentPage' => $page, 'lastPage' => max(1, (int) ceil(count($shown) / $perPage))],
        ];
    }

    // ─── Payments page summary ──────────────────────────────────────────────

    public function paymentsSummary(int $cid, array $in): array
    {
        $period = FinanceContext::period($cid, $in, 'this_month');
        $channel = FinanceContext::channel($in);
        $from = $period->fromDate();
        $to = $period->toDate();
        $orders = $this->metrics->orders($cid, $from, $to, $channel);
        $paid = $this->metrics->paid($cid, $from, $to, $channel, $orders['volume']);
        $refunds = $this->metrics->refunds($cid, $from, $to, $period, $channel);

        // money received by payment date (sale + COD + tailor payments)
        $recv = $this->metrics->fees($cid, $from, $to, $channel);
        $ledger = app(FinanceLedgerService::class);
        $rq = $ledger->applyPeriod($ledger->base($cid), $from, $to)->whereIn('l.source_type', FinanceFeeService::FEE_SOURCES)->where('l.direction', 'in');
        if ($channel !== 'all') {
            $rq->where('l.channel', $channel);
        }
        $r = $rq->selectRaw('COALESCE(SUM(l.amount),0) AS a, COUNT(*) AS n')->first();

        // current state (period independent)
        $base = fn () => DB::table('sells as s')->where('s.company_id', $cid)->whereNull('s.deleted_at')->where('s.status', '!=', 'Cancelled')->where('s.due_amount', '>', 0);
        $codSql = FinanceMethods::sqlKey('s.method') . " = 'cod'";
        $online = $base()->whereIn('s.source', ['storefront', 'online'])->whereRaw("NOT ({$codSql})")
            ->where(fn ($w) => $w->whereNull('s.payment_status')->orWhere('s.payment_status', '!=', 'paid'))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(s.due_amount),0) AS a')->first();
        $cod = $base()->whereRaw("({$codSql})")->selectRaw('COUNT(*) AS n, COALESCE(SUM(s.due_amount),0) AS a')->first();
        $aw = $this->metrics->awaitingRefunds($cid, null, null);
        $n = fn (int $c, string $one, string $many) => $c . ' ' . ($c === 1 ? $one : $many);

        $sup = $this->suppliers($cid);
        $paidSup = $ledger->applyPeriod($ledger->base($cid), $from, $to)->where('l.source_type', 'supplier_payment')->sum('l.amount');

        return [
            'context' => FinanceContext::context($cid, $period, $channel),
            'channelScope' => 'sales_only',
            'orderVolume' => ['amount' => $orders['volume'], 'count' => $orders['count']],
            'paidVolume' => $paid + ['estimatedNote' => 'Sales marked paid without a payment record (older data or online gateway). Shown separately, not counted as recorded payments.'],
            'received' => ['amount' => round((float) $r->a, 2), 'count' => (int) $r->n, 'fees' => $recv['total'], 'net' => round((float) $r->a - $recv['total'], 2)],
            'refunds' => $refunds,
            'needsALook' => [
                'asOf' => FinanceContext::todayDate($cid),
                'label' => 'As things stand now',
                'onlineUnconfirmed' => ['count' => (int) $online->n, 'amount' => round((float) $online->a, 2),
                    'label' => $n((int) $online->n, 'online payment not confirmed', 'online payments not confirmed'), 'href' => '/dashboard/orders?source=online&pay=unpaid'],
                'codAwaitingCollection' => ['count' => (int) $cod->n, 'amount' => round((float) $cod->a, 2),
                    'label' => $n((int) $cod->n, 'COD order awaiting collection', 'COD orders awaiting collection'), 'href' => '/dashboard/finance/receivables?tab=customers&cod=true'],
                'refundsAwaiting' => ['count' => $aw['count'], 'amount' => $aw['amount'],
                    'label' => $n($aw['count'], 'refund awaiting settlement', 'refunds awaiting settlement'), 'href' => '/dashboard/returns/customer'],
            ],
            'toSuppliers' => [
                'owed' => round(array_sum(array_column($sup, 'owed')), 2),
                'payableNow' => round(array_sum(array_map(fn ($x) => max(0, $x['payableNow']), $sup)), 2),
                'notYetDue' => round(array_sum(array_column($sup, 'notYetDue')), 2),
                'credits' => round(array_sum(array_column($sup, 'credits')), 2),
                'paidInPeriod' => round((float) $paidSup, 2),
                'payoutsHref' => '/dashboard/finance/payouts',
                'receivablesHref' => '/dashboard/finance/receivables?tab=suppliers',
            ],
        ];
    }
}
