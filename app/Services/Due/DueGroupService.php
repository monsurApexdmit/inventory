<?php

namespace App\Services\Due;

use App\Models\Company;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\DuePayment;
use App\Models\DueReminder;
use App\Models\Notification;
use App\Models\PurchaseOrder;
use App\Models\Sell;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\Notification\NotificationService;
use App\Services\Payment\DuePaymentService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Party-grouped due views (customers / vendors) built on top of the per-document
 * sells / purchase_orders due columns, plus allocation of one payment across documents.
 */
class DueGroupService
{
    private const BUCKETS = [
        0 => '0–30 days',
        1 => '31–60 days',
        2 => '61–90 days',
        3 => '90+ days',
    ];

    public function __construct(
        private readonly DuePaymentService $duePaymentService,
        private readonly NotificationService $notificationService,
    ) {
    }

    // ─── Summary ────────────────────────────────────────────────────────────

    public function summary(string $kind, int $companyId, array $filters): array
    {
        $isCustomer = $kind === 'customer';
        $rows = $this->buildRows($kind, $companyId);

        // search + payment filters apply to rows, aging and stats; bucket applies to rows only.
        $q = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $payment = $filters['payment'] ?? 'all';
        $scoped = $rows->filter(function ($r) use ($q, $payment) {
            if ($payment === 'partial' && !$r['hasPartial']) {
                return false;
            }
            if ($payment === 'unpaid' && $r['hasPartial']) {
                return false;
            }
            if ($q !== '') {
                $hay = mb_strtolower($r['name'] . ' ' . ($r['phone'] ?? '') . ' ' . ($r['email'] ?? '') . ' ' . implode(' ', $r['_allDocNos']));
                if (!str_contains($hay, $q)) {
                    return false;
                }
            }
            return true;
        })->values();

        $aging = [];
        foreach (self::BUCKETS as $key => $label) {
            $in = $scoped->filter(fn ($r) => $this->bucketOf($r['ageDays']) === $key);
            $aging[] = ['key' => $key, 'label' => $label, 'amount' => round($in->sum('due'), 2), 'count' => $in->count()];
        }

        $shown = $scoped;
        if (isset($filters['bucket']) && $filters['bucket'] !== '' && $filters['bucket'] !== 'all') {
            $b = (int) $filters['bucket'];
            $shown = $shown->filter(fn ($r) => $this->bucketOf($r['ageDays']) === $b);
        }
        $sort = $filters['sort'] ?? 'age';
        if ($sort === 'amount') {
            $shown = $shown->sortByDesc('due');
        } elseif ($sort === 'name') {
            $shown = $shown->sortBy(fn ($r) => mb_strtolower($r['name']));
        } else {
            // "Most overdue": suppliers with a missed due date first, then oldest document.
            $shown = $shown->sortByDesc(fn ($r) => [(int) ($r['overdue'] ?? false), $r['ageDays'], $r['due']]);
        }
        $shown = $shown->values();

        $perPage = max(1, min((int) ($filters['per_page'] ?? 100), 500));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = $shown->count();
        $pageRows = $shown->slice(($page - 1) * $perPage, $perPage)->map(fn ($r) => $this->publicRow($r))->values()->all();

        $stats = $isCustomer ? $this->customerStats($companyId, $scoped) : $this->vendorStats($companyId, $scoped);

        return [
            'data' => ['rows' => $pageRows, 'aging' => $aging, 'stats' => $stats],
            'meta' => ['total' => $total, 'per_page' => $perPage, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    private function customerStats(int $companyId, Collection $scoped): array
    {
        $ids = $scoped->pluck('partyId')->all();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $paid = DuePayment::query()
            ->join('sells', function ($j) {
                $j->on('sells.id', '=', 'due_payments.payable_id')->where('due_payments.payable_type', '=', Sell::class);
            })
            ->where('due_payments.company_id', $companyId)
            ->where('due_payments.direction', 'in')
            ->whereBetween('due_payments.payment_date', [$monthStart, $monthEnd])
            ->when($ids, function ($q) use ($ids) {
                // partyId 0 is the walk-in pseudo party (sells without a customer)
                $q->where(function ($w) use ($ids) {
                    $w->whereIn('sells.customer_id', $ids);
                    if (in_array(0, $ids, true)) {
                        $w->orWhereNull('sells.customer_id');
                    }
                });
            }, fn ($q) => $q->whereRaw('1 = 0'))
            ->selectRaw('COALESCE(SUM(due_payments.amount), 0) as amt, COUNT(*) as cnt')
            ->first();

        $old = $scoped->filter(fn ($r) => $r['ageDays'] > 30);
        $total = round($scoped->sum('due'), 2);
        $open = (int) $scoped->sum('docs');

        $walkIn = (float) Sell::where('company_id', $companyId)->whereNull('customer_id')->where('due_amount', '>', 0)->where('status', '!=', 'Cancelled')->sum('due_amount');

        return [
            'totalDue' => $total,
            'partyCount' => $scoped->count(),
            'overdue30Amount' => round($old->sum('due'), 2),
            'overdue30Parties' => $old->count(),
            'collectedThisMonth' => round((float) $paid->amt, 2),
            'collectedThisMonthCount' => (int) $paid->cnt,
            'openInvoices' => $open,
            'avgInvoice' => $open > 0 ? round($total / $open, 2) : 0.0,
            'walkInDue' => round($walkIn, 2),
        ];
    }

    private function vendorStats(int $companyId, Collection $scoped): array
    {
        $ids = $scoped->pluck('partyId')->all();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $po = DuePayment::query()
            ->join('purchase_orders as po', function ($j) {
                $j->on('po.id', '=', 'due_payments.payable_id')->where('due_payments.payable_type', '=', PurchaseOrder::class);
            })
            ->where('due_payments.company_id', $companyId)
            ->where('due_payments.direction', 'out')
            ->whereBetween('due_payments.payment_date', [$monthStart, $monthEnd])
            ->when($ids, fn ($q) => $q->whereIn('po.vendor_id', $ids), fn ($q) => $q->whereRaw('1 = 0'))
            ->selectRaw('COALESCE(SUM(due_payments.amount), 0) as amt, COUNT(*) as cnt')->first();
        $ob = VendorPayment::query()
            ->where('company_id', $companyId)
            ->whereBetween('payment_date', [$monthStart, $monthEnd])
            ->when($ids, fn ($q) => $q->whereIn('vendor_id', $ids), fn ($q) => $q->whereRaw('1 = 0'))
            ->selectRaw('COALESCE(SUM(amount), 0) as amt, COUNT(*) as cnt')->first();

        $overdue = $scoped->filter(fn ($r) => $r['_overdueAmount'] > 0);
        $soon = $scoped->filter(fn ($r) => $r['_dueSoonAmount'] > 0);
        $total = round($scoped->sum('due'), 2);
        $open = (int) $scoped->sum('docs');

        return [
            'totalDue' => $total,
            'partyCount' => $scoped->count(),
            'overdueAmount' => round($overdue->sum('_overdueAmount'), 2),
            'overdueParties' => $overdue->count(),
            'dueNext7Amount' => round($soon->sum('_dueSoonAmount'), 2),
            'dueNext7Parties' => $soon->count(),
            'paidThisMonth' => round((float) $po->amt + (float) $ob->amt, 2),
            'paidThisMonthCount' => (int) $po->cnt + (int) $ob->cnt,
            'openBills' => $open,
            'avg' => $open > 0 ? round($total / $open, 2) : 0.0,
        ];
    }

    // ─── Documents ──────────────────────────────────────────────────────────

    /** Flat list of unpaid documents for every party (or one party), oldest first. */
    private function loadDocs(string $kind, int $companyId, ?int $partyId = null, bool $lock = false): Collection
    {
        $today = now()->startOfDay();
        $docs = collect();

        if ($kind === 'customer') {
            $q = Sell::query()
                ->from('sells')
                ->leftJoin('customers', 'customers.id', '=', 'sells.customer_id')
                ->where('sells.company_id', $companyId)
                ->whereNull('sells.deleted_at')
                ->where('sells.status', '!=', 'Cancelled')
                ->where('sells.due_amount', '>', 0)
                // Walk-in dues (no customer on the sell) are grouped as pseudo party 0 so they stay collectable.
                ->when($partyId !== null, fn ($q) => $partyId === 0 ? $q->whereNull('sells.customer_id') : $q->where('sells.customer_id', $partyId))
                ->orderByRaw('COALESCE(sells.order_time, sells.created_at) ASC')->orderBy('sells.id')
                ->select('sells.*', 'customers.name as p_name', 'customers.phone as p_phone', 'customers.email as p_email', 'customers.customer_group as p_group');
            if ($lock) {
                $q->lockForUpdate();
            }
            foreach ($q->get() as $s) {
                $date = Carbon::parse($s->order_time ?? $s->created_at)->startOfDay();
                $docs->push([
                    'partyId' => (int) $s->customer_id,
                    'name' => $s->customer_id ? ($s->p_name ?: ($s->customer_name ?: 'Unknown')) : 'Walk-in customers',
                    'phone' => $s->p_phone, 'email' => $s->p_email, 'group' => $s->p_group, 'status' => null,
                    'documentType' => 'sell', 'documentId' => (int) $s->id, 'no' => $s->invoice_no, 'date' => $date,
                    'ageDays' => max(0, (int) $date->diffInDays($today)),
                    'total' => (float) $s->amount, 'paid' => (float) $s->paid_amount, 'due' => (float) $s->due_amount, 'dueDate' => null,
                ]);
            }
            return $docs;
        }

        $q = PurchaseOrder::query()
            ->from('purchase_orders')
            ->join('vendors', 'vendors.id', '=', 'purchase_orders.vendor_id')
            ->where('purchase_orders.company_id', $companyId)
            ->whereIn('purchase_orders.status', ['draft', 'sent', 'partial', 'received'])
            ->where('purchase_orders.due_amount', '>', 0)
            ->when($partyId, fn ($q) => $q->where('purchase_orders.vendor_id', $partyId))
            ->orderBy('purchase_orders.created_at')->orderBy('purchase_orders.id')
            ->select('purchase_orders.*', 'vendors.name as p_name', 'vendors.phone as p_phone', 'vendors.email as p_email');
        if ($lock) {
            $q->lockForUpdate();
        }
        foreach ($q->get() as $p) {
            $date = Carbon::parse($p->created_at)->startOfDay();
            $docs->push([
                'partyId' => (int) $p->vendor_id, 'name' => $p->p_name, 'phone' => $p->p_phone, 'email' => $p->p_email,
                'status' => $p->status, 'documentType' => 'purchase_order', 'documentId' => (int) $p->id, 'no' => $p->po_number, 'date' => $date,
                'ageDays' => max(0, (int) $date->diffInDays($today)),
                'total' => (float) $p->total_amount, 'paid' => (float) $p->paid_amount, 'due' => (float) $p->due_amount,
                'dueDate' => $p->expected_date ? Carbon::parse($p->expected_date)->startOfDay() : null,
            ]);
        }

        $vq = Vendor::query()->where('company_id', $companyId)->where('opening_balance', '>', 0)
            ->when($partyId, fn ($q) => $q->where('id', $partyId));
        if ($lock) {
            $vq->lockForUpdate();
        }
        $opening = $vq->get();
        $paidOb = VendorPayment::where('company_id', $companyId)
            ->whereIn('vendor_id', $opening->pluck('id'))->groupBy('vendor_id')
            ->selectRaw('vendor_id, SUM(amount) as amt')->pluck('amt', 'vendor_id');
        foreach ($opening as $v) {
            $date = Carbon::parse($v->created_at)->startOfDay();
            $paid = (float) ($paidOb[$v->id] ?? 0);
            $docs->push([
                'partyId' => (int) $v->id, 'name' => $v->name, 'phone' => $v->phone, 'email' => $v->email,
                'status' => null, 'documentType' => 'opening_balance', 'documentId' => null, 'no' => 'Opening balance', 'date' => $date,
                'ageDays' => max(0, (int) $date->diffInDays($today)),
                'total' => round((float) $v->opening_balance + $paid, 2), 'paid' => $paid, 'due' => (float) $v->opening_balance, 'dueDate' => null,
            ]);
        }

        // Oldest first; the opening balance is paid last by design.
        return $docs->sortBy(fn ($d) => [$d['documentType'] === 'opening_balance' ? 1 : 0, $d['date']->timestamp, $d['documentId'] ?? 0])->values();
    }

    private function buildRows(string $kind, int $companyId, ?int $partyId = null): Collection
    {
        $docs = $this->loadDocs($kind, $companyId, $partyId);
        $today = now()->startOfDay();
        $weekEnd = $today->copy()->addDays(7);
        $lastPay = $this->lastPaymentDates($kind, $companyId, $docs->pluck('partyId')->unique()->all());
        $reminders = $kind === 'customer' ? $this->lastReminders($companyId) : [];

        return $docs->groupBy('partyId')->map(function (Collection $g, $pid) use ($today, $weekEnd, $lastPay, $reminders, $kind) {
            $first = $g->first();
            // oldest by date (opening balance included by date for ageing purposes)
            $oldest = $g->sortBy(fn ($d) => $d['date']->timestamp)->first();
            $nos = $g->pluck('no')->all();
            $row = [
                'partyId' => (int) $pid,
                'name' => $first['name'],
                'phone' => $first['phone'],
                'email' => $first['email'],
                'walkIn' => $kind === 'customer' && $pid === 0,
                'docs' => $g->count(),
                'due' => round($g->sum('due'), 2),
                'oldestDate' => $oldest['date']->format('Y-m-d'),
                'ageDays' => $oldest['ageDays'],
                'oldestDocNo' => $oldest['no'],
                'lastPaymentDate' => $lastPay[$pid] ?? null,
                'paidSoFar' => round($g->sum('paid'), 2),
                'hasPartial' => $g->contains(fn ($d) => $d['paid'] > 0),
                'lastDocNos' => array_slice($nos, 0, 10),
                '_allDocNos' => $nos,
            ];
            if ($kind === 'customer') {
                $row['lastReminder'] = $reminders[$pid] ?? null;
                $row['group'] = $first['group'] ?? null;
            } else {
                $dated = $g->filter(fn ($d) => $d['dueDate'] !== null);
                $minDue = $dated->min(fn ($d) => $d['dueDate']->timestamp);
                $row['dueDate'] = $minDue ? Carbon::createFromTimestamp($minDue)->format('Y-m-d') : null;
                $row['_overdueAmount'] = round($dated->filter(fn ($d) => $d['dueDate']->lt($today))->sum('due'), 2);
                $row['_dueSoonAmount'] = round($dated->filter(fn ($d) => $d['dueDate']->gte($today) && $d['dueDate']->lte($weekEnd))->sum('due'), 2);
                $row['overdue'] = $row['_overdueAmount'] > 0;
                $row['dueInDays'] = $minDue ? (int) $today->diffInDays(Carbon::createFromTimestamp($minDue)->startOfDay(), false) : null;
                $row['statuses'] = $g->pluck('status')->filter()->unique()->values()->all();
            }
            return $row;
        })->values();
    }

    private function publicRow(array $r): array
    {
        return array_filter($r, fn ($k) => !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY);
    }

    private function bucketOf(int $days): int
    {
        return $days <= 30 ? 0 : ($days <= 60 ? 1 : ($days <= 90 ? 2 : 3));
    }

    private function lastPaymentDates(string $kind, int $companyId, array $partyIds): array
    {
        if (!$partyIds) {
            return [];
        }
        if ($kind === 'customer') {
            return DuePayment::query()
                ->join('sells', function ($j) {
                    $j->on('sells.id', '=', 'due_payments.payable_id')->where('due_payments.payable_type', '=', Sell::class);
                })
                ->where('due_payments.company_id', $companyId)->where('due_payments.direction', 'in')
                ->where(function ($w) use ($partyIds) {
                    $w->whereIn('sells.customer_id', $partyIds);
                    if (in_array(0, $partyIds, true)) {
                        $w->orWhereNull('sells.customer_id');
                    }
                })->groupByRaw('COALESCE(sells.customer_id, 0)')
                ->selectRaw('COALESCE(sells.customer_id, 0) as pid, MAX(due_payments.payment_date) as d')->pluck('d', 'pid')
                ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))->all();
        }
        $a = DuePayment::query()
            ->join('purchase_orders as po', function ($j) {
                $j->on('po.id', '=', 'due_payments.payable_id')->where('due_payments.payable_type', '=', PurchaseOrder::class);
            })
            ->where('due_payments.company_id', $companyId)->where('due_payments.direction', 'out')
            ->whereIn('po.vendor_id', $partyIds)->groupBy('po.vendor_id')
            ->selectRaw('po.vendor_id as pid, MAX(due_payments.payment_date) as d')->pluck('d', 'pid')->all();
        $b = VendorPayment::where('company_id', $companyId)->whereIn('vendor_id', $partyIds)->groupBy('vendor_id')
            ->selectRaw('vendor_id as pid, MAX(payment_date) as d')->pluck('d', 'pid')->all();
        $out = [];
        foreach ($partyIds as $id) {
            $d = max($a[$id] ?? '', $b[$id] ?? '');
            if ($d !== '') {
                $out[$id] = Carbon::parse($d)->format('Y-m-d');
            }
        }
        return $out;
    }

    private function lastReminders(int $companyId): array
    {
        $out = [];
        // Older reminders were only logged as notifications; keep them as a fallback.
        Notification::where('company_id', $companyId)->where('type', 'payment_reminder')
            ->orderBy('id')->limit(2000)->get()->each(function ($n) use (&$out) {
                $pid = $n->data['partyId'] ?? null;
                if ($pid) {
                    $out[$pid] = ['channel' => $n->data['channel'] ?? null, 'at' => $n->created_at?->toIso8601String()];
                }
            });
        DueReminder::where('company_id', $companyId)->orderBy('id')->get(['customer_id', 'channel', 'created_at'])
            ->each(function ($r) use (&$out) {
                $out[$r->customer_id] = ['channel' => $r->channel, 'at' => $r->created_at?->toIso8601String()];
            });

        return $out;
    }

    public function documents(string $kind, int $companyId, int $partyId): array
    {
        $party = $this->party($kind, $companyId, $partyId);
        $docs = $this->loadDocs($kind, $companyId, $partyId)->map(fn ($d) => [
            'documentType' => $d['documentType'],
            'documentId' => $d['documentId'],
            'no' => $d['no'],
            'date' => $d['date']->format('Y-m-d'),
            'ageDays' => $d['ageDays'],
            'total' => round($d['total'], 2),
            'paid' => round($d['paid'], 2),
            'due' => round($d['due'], 2),
            'status' => $d['status'] ?? null,
            'dueDate' => isset($d['dueDate']) && $d['dueDate'] ? $d['dueDate']->format('Y-m-d') : null,
        ])->values()->all();

        return ['party' => ['id' => $party->id, 'name' => $party->name, 'phone' => $party->phone], 'documents' => $docs];
    }

    private function party(string $kind, int $companyId, int $id)
    {
        if ($kind === 'customer' && $id === 0) {
            return (object) ['id' => 0, 'name' => 'Walk-in customers', 'phone' => null, 'email' => null];
        }
        $party = $kind === 'customer'
            ? Customer::where('company_id', $companyId)->find($id)
            : Vendor::where('company_id', $companyId)->find($id);
        if (!$party) {
            throw new HttpException(404, ucfirst($kind) . ' not found');
        }
        return $party;
    }

    // ─── Payment allocation ─────────────────────────────────────────────────

    public function pay(string $kind, int $companyId, int $partyId, array $data, ?int $recordedBy): array
    {
        $this->party($kind, $companyId, $partyId);
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new HttpException(422, 'Payment amount must be greater than zero');
        }
        $method = $data['method'] ?? 'cash';
        $date = $data['date'] ?? now()->toDateString();
        // One day of slack so a browser ahead of the server timezone is not rejected.
        if (Carbon::parse($date)->startOfDay()->gt(now()->addDay()->startOfDay())) {
            throw new HttpException(422, 'Payment date cannot be in the future.');
        }
        $reference = $data['reference'] ?? null;
        $notes = $data['notes'] ?? null;

        $allocations = DB::transaction(function () use ($kind, $companyId, $partyId, $amount, $method, $date, $reference, $notes, $recordedBy, $data) {
            $docs = $this->loadDocs($kind, $companyId, $partyId, true);

            if (!empty($data['documentIds'])) {
                $wanted = collect($data['documentIds'])->map(fn ($d) => ($d['type'] ?? '') . ':' . ($d['id'] ?? ''))->all();
                $selected = $docs->filter(fn ($d) => in_array($d['documentType'] . ':' . ($d['documentId'] ?? ''), $wanted, true))->values();
                if ($selected->count() !== count(array_unique($wanted))) {
                    throw new HttpException(422, 'One or more selected documents are not outstanding for this party');
                }
                $docs = $selected;
            }

            $owed = round($docs->sum('due'), 2);
            if ($amount > $owed) {
                throw new HttpException(422, 'Payment amount exceeds the selected documents\' total due (' . number_format($owed, 2, '.', '') . ')');
            }

            $remaining = $amount;
            $alloc = [];
            foreach ($docs as $doc) {
                if ($remaining <= 0) {
                    break;
                }
                $pay = round(min($remaining, $doc['due']), 2);
                if ($pay <= 0) {
                    continue;
                }
                if ($doc['documentType'] === 'opening_balance') {
                    VendorPayment::create([
                        'company_id' => $companyId, 'vendor_id' => $partyId, 'amount' => $pay,
                        'payment_method' => $method, 'payment_date' => $date, 'notes' => $notes, 'recorded_by' => $recordedBy,
                    ]);
                    Vendor::where('id', $partyId)->update(['opening_balance' => max(0, round($doc['due'] - $pay, 2))]);
                } else {
                    $this->duePaymentService->recordPayment(
                        $doc['documentType'] === 'sell' ? 'sells' : 'purchase-orders',
                        $doc['documentId'],
                        ['amount' => $pay, 'payment_method' => $method, 'payment_date' => $date, 'reference' => $reference, 'notes' => $notes],
                        $companyId,
                        $recordedBy
                    );
                }
                $alloc[] = ['documentType' => $doc['documentType'], 'documentId' => $doc['documentId'], 'no' => $doc['no'], 'amount' => $pay];
                $remaining = round($remaining - $pay, 2);
            }

            return $alloc;
        });

        $row = $this->buildRows($kind, $companyId, $partyId)->first();

        return [
            'allocations' => $allocations,
            'party' => $row ? $this->publicRow($row) : [
                'partyId' => $partyId, 'name' => $this->party($kind, $companyId, $partyId)->name, 'docs' => 0, 'due' => 0.0,
            ],
        ];
    }

    // ─── History ────────────────────────────────────────────────────────────

    public function history(int $companyId, string $direction, int $limit): array
    {
        $limit = max(1, min($limit, 100));

        if ($direction === 'in') {
            return DuePayment::query()
                ->join('sells', function ($j) {
                    $j->on('sells.id', '=', 'due_payments.payable_id')->where('due_payments.payable_type', '=', Sell::class);
                })
                ->leftJoin('customers', 'customers.id', '=', 'sells.customer_id')
                ->where('due_payments.company_id', $companyId)->where('due_payments.direction', 'in')
                ->orderByDesc('due_payments.payment_date')->orderByDesc('due_payments.id')->limit($limit)
                ->get(['due_payments.*', 'sells.invoice_no', 'sells.customer_name', 'customers.name as c_name'])
                ->map(fn ($p) => $this->historyRow('dp-' . $p->id, $p->c_name ?: ($p->customer_name ?: 'Unknown'), $p->reference, $p->notes, $p->payment_date, $p->payment_method, $p->amount, $p->invoice_no))
                ->all();
        }

        $po = DuePayment::query()
            ->join('purchase_orders as po', function ($j) {
                $j->on('po.id', '=', 'due_payments.payable_id')->where('due_payments.payable_type', '=', PurchaseOrder::class);
            })
            ->join('vendors', 'vendors.id', '=', 'po.vendor_id')
            ->where('due_payments.company_id', $companyId)->where('due_payments.direction', 'out')
            ->orderByDesc('due_payments.payment_date')->orderByDesc('due_payments.id')->limit($limit)
            ->get(['due_payments.*', 'po.po_number', 'vendors.name as v_name'])
            ->map(fn ($p) => $this->historyRow('dp-' . $p->id, $p->v_name, $p->reference, $p->notes, $p->payment_date, $p->payment_method, $p->amount, $p->po_number) + ['_k' => $p->id]);
        $ob = VendorPayment::query()
            ->join('vendors', 'vendors.id', '=', 'vendor_payments.vendor_id')
            ->where('vendor_payments.company_id', $companyId)
            ->orderByDesc('vendor_payments.payment_date')->orderByDesc('vendor_payments.id')->limit($limit)
            ->get(['vendor_payments.*', 'vendors.name as v_name'])
            ->map(fn ($p) => $this->historyRow('vp-' . $p->id, $p->v_name, null, $p->notes, $p->payment_date, $p->payment_method, $p->amount, 'Opening balance') + ['_k' => $p->id]);

        return $po->concat($ob)
            ->sort(fn ($a, $b) => [$b['date'], $b['_k']] <=> [$a['date'], $a['_k']])
            ->take($limit)->map(function ($r) {
                unset($r['_k']);
                return $r;
            })->values()->all();
    }

    private function historyRow($id, $party, $ref, $notes, $date, $method, $amount, $docNo): array
    {
        return [
            'id' => $id, 'partyName' => $party, 'reference' => $ref, 'notes' => $notes,
            'date' => $date ? Carbon::parse($date)->format('Y-m-d') : null,
            'method' => $method, 'amount' => (float) $amount, 'documentNo' => $docNo,
        ];
    }

    // ─── Reminder ───────────────────────────────────────────────────────────

    public function remind(int $companyId, int $customerId, string $channel, bool $preview = false, ?int $recordedBy = null): array
    {
        if ($customerId === 0) {
            throw new HttpException(422, 'Walk-in sales have no customer to remind');
        }
        $customer = $this->party('customer', $companyId, $customerId);
        $docs = $this->loadDocs('customer', $companyId, $customerId);
        if ($docs->isEmpty()) {
            throw new HttpException(422, 'This customer has no outstanding dues');
        }

        $due = round($docs->sum('due'), 2);
        $nos = $docs->pluck('no')->all();
        $first = explode(' ', trim($customer->name))[0] ?: $customer->name;
        $amountText = $this->money($companyId, $due);
        $store = CompanySettings::where('company_id', $companyId)->value('company_name') ?: Company::where('id', $companyId)->value('name');

        $message = 'Hello ' . $first . ', a friendly reminder that ' . $amountText . ' is due for '
            . (count($nos) === 1 ? 'invoice ' : 'invoices ') . implode(', ', $nos) . '. Thank you! — ' . $store;

        if (!$preview) {
            DueReminder::create([
                'company_id' => $companyId, 'customer_id' => $customerId, 'channel' => $channel,
                'amount' => $due, 'message' => $message, 'recorded_by' => $recordedBy,
            ]);
            $this->notificationService->notifyPaymentReminder($companyId, $customer->name, $amountText, $channel, $customerId, $message, $due);
        }

        return ['message' => $message, 'lastReminder' => $preview ? ($this->lastReminders($companyId)[$customerId] ?? null) : ['channel' => $channel, 'at' => now()->toIso8601String()]];
    }

    private function money(int $companyId, float $amount): string
    {
        $s = CompanySettings::where('company_id', $companyId)->first();
        $code = $s?->currency ?: Company::where('id', $companyId)->value('currency');
        $symbol = ['BDT' => '৳', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'INR' => '₹', 'JPY' => '¥'][strtoupper((string) $code)] ?? null;
        $dec = (int) ($s?->currency_decimal_places ?? 2);
        $num = number_format($amount, $dec, $s?->currency_decimal_separator ?: '.', $s?->currency_thousands_separator ?? ',');
        $symbol ??= $code ? strtoupper($code) . ' ' : '';
        $after = ($s?->currency_symbol_position ?? 'before') === 'after';

        return $after ? $num . trim($symbol) : $symbol . $num;
    }
}
