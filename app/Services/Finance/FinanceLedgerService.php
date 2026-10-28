<?php

namespace App\Services\Finance;

use App\DTOs\Finance\FinanceEntryDTO;
use App\DTOs\Finance\FinanceEntryMapper;
use App\DTOs\Finance\FinanceTransferDTO;
use App\DTOs\Finance\FinanceTransferMapper;
use App\Repositories\Contracts\IFinanceEntryRepository;
use App\Repositories\Contracts\IFinanceLedgerRepository;
use App\Repositories\Contracts\IFinanceTransferRepository;
use App\Support\ActorName;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Transactions (computed ledger), manual entries and transfers. */
class FinanceLedgerService
{
    public const SOURCE_LABELS = [
        'sale' => 'Sale payment',
        'cod' => 'COD collection',
        'refund' => 'Customer refund',
        'supplier_payment' => 'Supplier payment',
        'supplier_refund' => 'Supplier refund',
        'expense' => 'Expense',
        'salary' => 'Salary',
        'dorji_payout' => 'Dorji payout',
        'tailor_payment' => 'Tailor payment',
        'manual' => 'Manual entry',
        'transfer' => 'Transfer',
        'expense_void' => 'Expense voided',
    ];

    public const CSV_CAP = 10000;

    private readonly FinanceEntryMapper $entryMapper;
    private readonly FinanceTransferMapper $transferMapper;

    public function __construct(
        private readonly IFinanceLedgerRepository $ledger,
        private readonly IFinanceEntryRepository $entries,
        private readonly IFinanceTransferRepository $transfers,
        private readonly FinanceAccountService $accounts,
        private readonly FinanceFeeService $fees,
    ) {
        $this->entryMapper = new FinanceEntryMapper();
        $this->transferMapper = new FinanceTransferMapper();
    }

    // ─── Query building ─────────────────────────────────────────────────────

    public function base(int $companyId): Builder
    {
        return $this->ledger->query($companyId);
    }

    public function applyPeriod(Builder $q, ?string $from, ?string $to): Builder
    {
        return $q->when($from, fn ($x) => $x->where('l.txn_date', '>=', $from))
            ->when($to, fn ($x) => $x->where('l.txn_date', '<=', $to));
    }

    /** @return string[] validated source types from a string ("a,b") or array */
    public function sourceTypes(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);
        $out = [];
        foreach ($list as $t) {
            $t = strtolower(trim((string) $t));
            if ($t === '' || $t === 'all') {
                continue;
            }
            if (!isset(self::SOURCE_LABELS[$t])) {
                throw new HttpException(422, "Unknown source type: {$t}.");
            }
            $out[] = $t;
        }

        return array_values(array_unique($out));
    }

    /**
     * Apply the common list filters (accountId, direction, sourceType(s), method, search).
     * $f keys: accountId, direction, sourceTypes (array), method, search.
     */
    public function applyFilters(Builder $q, FinanceAccountMap $map, array $f): Builder
    {
        if (!empty($f['accountId'])) {
            $map->applyAccountFilter($q, (int) $f['accountId']);
        }
        if (!empty($f['direction'])) {
            if (!in_array($f['direction'], ['in', 'out'], true)) {
                throw new HttpException(422, 'Direction must be in or out.');
            }
            $q->where('l.direction', $f['direction']);
        }
        if (!empty($f['sourceTypes'])) {
            $q->whereIn('l.source_type', $f['sourceTypes']);
        }
        if (!empty($f['channel']) && $f['channel'] !== 'all') {
            $q->where('l.channel', $f['channel']);
        }
        if (!empty($f['method']) && $f['method'] !== 'all') {
            $q->where('l.mkey', FinanceMethods::key((string) $f['method']));
        }
        $s = trim((string) ($f['search'] ?? ''));
        if ($s !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
            $q->where(function ($w) use ($like) {
                foreach (['l.party', 'l.reference', 'l.note', 'l.category', 'l.method'] as $col) {
                    $w->orWhere($col, 'like', $like);
                }
            });
        }

        return $q;
    }

    /**
     * @return array{moneyIn: float, moneyOut: float, net: float, count: int, gross: float, fees: float, netAfterFees: float}
     * Fees are estimated from the company's payment-method fee settings when $cid is given.
     */
    public function totals(Builder $q, ?int $cid = null): array
    {
        $fee = $cid ? $this->fees->sql($cid) : '0';
        $t = (clone $q)->selectRaw("COALESCE(SUM(CASE WHEN l.direction = 'in' THEN l.amount ELSE 0 END), 0) AS i,
            COALESCE(SUM(CASE WHEN l.direction = 'out' THEN l.amount ELSE 0 END), 0) AS o, COUNT(*) AS n,
            COALESCE(SUM({$fee}), 0) AS f")->first();
        $in = round((float) $t->i, 2);
        $out = round((float) $t->o, 2);
        $fees = round((float) $t->f, 2);
        $net = round($in - $out, 2);

        return ['moneyIn' => $in, 'moneyOut' => $out, 'net' => $net, 'count' => (int) $t->n, 'gross' => $net, 'fees' => $fees, 'netAfterFees' => round($net - $fees, 2)];
    }

    public function ordered(Builder $q, string $sort = 'date_desc'): Builder
    {
        $dir = $sort === 'date_asc' ? 'asc' : 'desc';

        return $q->orderBy('l.txn_date', $dir)->orderBy('l.created_at', $dir)->orderBy('l.uid', $dir);
    }

    // ─── Row shape ──────────────────────────────────────────────────────────

    public function mapRow(object $r, FinanceAccountMap $map, ?float $running = null): array
    {
        $accountId = $map->resolve($r->account_id, $r->mkey);
        $amount = round((float) $r->amount, 2);
        $src = (string) $r->source_type;
        $cid = $map->companyId;
        $created = $r->created_at ? ($cid ? FinanceContext::iso($cid, $r->created_at) : Carbon::parse($r->created_at)->toIso8601String()) : null;
        $isEntry = str_starts_with((string) $r->uid, 'entry:');
        $signed = $r->direction === 'out' ? -$amount : $amount;
        $fee = $cid ? $this->fees->fee($cid, $src, (string) $r->direction, $r->mkey, $amount) : 0.0;

        return [
            'id' => (string) $r->uid,
            'sourceType' => $src,
            'sourceLabel' => self::SOURCE_LABELS[$src] ?? ucfirst($src),
            'date' => $r->txn_date ? substr((string) $r->txn_date, 0, 10) : null,
            'createdAt' => $created,
            'direction' => (string) $r->direction,
            'amount' => $amount,
            'signedAmount' => $signed,
            'gross' => $signed,
            'fee' => $fee,
            'net' => round($signed - $fee, 2),
            'currency' => $cid ? FinanceContext::currencyCode($cid) : null,
            'channel' => $r->channel ?? null,
            'sellId' => isset($r->sell_id) && $r->sell_id !== null ? (int) $r->sell_id : null,
            'accountId' => $accountId,
            'accountName' => $map->name($accountId),
            'method' => $r->method,
            'methodKey' => $r->mkey,
            'methodLabel' => FinanceMethods::label($r->mkey),
            'party' => $r->party,
            'partyId' => $r->party_id !== null ? (int) $r->party_id : null,
            'reference' => $r->reference,
            'category' => $r->category,
            'note' => $r->note,
            'kind' => $isEntry ? $r->link_ref : null,
            'link' => $this->link($r),
            'editable' => in_array($src, ['manual', 'transfer'], true),
            'entryId' => $isEntry ? (int) $r->source_id : null,
            'transferId' => $src === 'transfer' ? (int) $r->source_id : null,
            'runningBalance' => $running,
        ];
    }

    private function link(object $r): ?array
    {
        $id = $r->link_id !== null ? (int) $r->link_id : null;
        $href = match ($r->link_type) {
            'sale' => '/dashboard/sales?q=' . rawurlencode((string) $r->link_ref),
            'purchase_order' => $r->link_ref ? '/dashboard/vendors/' . $r->link_ref : '/dashboard/vendors/purchase-orders',
            'vendor' => '/dashboard/vendors/' . $id,
            'expense' => '/dashboard/expenses' . ($id ? '?expense=' . $id : ''),
            'salary' => $id ? '/dashboard/staff/salary?staff=' . $id : '/dashboard/staff/salary',
            'tailor_order' => $id ? '/dashboard/tailor/orders/' . $id : '/dashboard/tailor/payments',
            'tailor_payments' => '/dashboard/tailor/payments',
            'customer_return' => '/dashboard/returns/customer',
            'vendor_return' => '/dashboard/returns/vendor',
            default => null,
        };

        return $href === null ? null : ['type' => (string) $r->link_type, 'id' => $id, 'href' => $href];
    }

    // ─── Transactions list ──────────────────────────────────────────────────

    /**
     * @return array{data: array, meta: array}|array{csv: array}
     */
    public function transactions(int $companyId, array $in, bool $csv = false): array
    {
        $period = FinanceContext::period($companyId, $in, 'all_time');
        $channel = FinanceContext::channel($in);
        $map = $this->accounts->map($companyId);
        $accountId = !empty($in['accountId']) ? (int) $in['accountId'] : null;
        $account = $accountId ? $this->accounts->find($accountId, $companyId) : null;

        $f = [
            'accountId' => $accountId,
            'direction' => $in['direction'] ?? null,
            'sourceTypes' => $this->sourceTypes($in['sourceType'] ?? null),
            'method' => $in['method'] ?? null,
            'search' => $in['search'] ?? null,
            'channel' => $channel,
        ];
        $q = $this->applyFilters($this->applyPeriod($this->base($companyId), $period->fromDate(), $period->toDate()), $map, $f);
        $sort = ($in['sort'] ?? 'date_desc') === 'date_asc' ? 'date_asc' : 'date_desc';

        // Running balances over every line of the account in the period (independent of the other filters).
        $running = [];
        $opening = $closing = null;
        if ($account) {
            [$opening, $closing, $running] = $this->runningBalances($companyId, $map, $account->id, $period->fromDate(), $period->toDate());
        }

        if ($csv) {
            $rows = $this->ordered(clone $q, $sort)->limit(self::CSV_CAP)->get();

            return ['csv' => $rows->map(fn ($r) => $this->mapRow($r, $map, $running[$r->uid] ?? null))->all()];
        }

        $perPage = max(1, min(100, (int) ($in['perPage'] ?? $in['per_page'] ?? 25)));
        $page = max(1, (int) ($in['page'] ?? 1));
        $totals = $this->totals($q, $companyId);
        $rows = $this->ordered(clone $q, $sort)->forPage($page, $perPage)->get();

        return [
            'data' => [
                'rows' => $rows->map(fn ($r) => $this->mapRow($r, $map, $account ? ($running[$r->uid] ?? null) : null))->all(),
                'totals' => $totals,
                'account' => $account ? $this->accounts->get($account->id, $companyId)->toArray() : null,
                'openingBalance' => $opening,
                'closingBalance' => $closing,
                'period' => $period->toArray(),
                'channelScope' => 'sales_only',
                'context' => FinanceContext::context($companyId, $period, $channel),
            ],
            'meta' => $this->meta($totals['count'], $perPage, $page),
        ];
    }

    public function meta(int $total, int $perPage, int $page): array
    {
        return ['total' => $total, 'perPage' => $perPage, 'currentPage' => $page, 'lastPage' => max(1, (int) ceil($total / $perPage))];
    }

    /**
     * Opening balance before $from, closing balance at $to and uid => balance after each line.
     *
     * @return array{0: float, 1: float, 2: array<string, float>}
     */
    public function runningBalances(int $companyId, FinanceAccountMap $map, int $accountId, ?string $from, ?string $to): array
    {
        $acc = $map->accounts[$accountId] ?? null;
        $opening = (float) ($acc?->opening_balance ?? 0);
        if ($from) {
            $before = $this->base($companyId)->where('l.txn_date', '<', $from);
            $map->applyAccountFilter($before, $accountId);
            $t = $this->totals($before);
            $opening += $t['moneyIn'] - $t['moneyOut'];
        }
        $q = $this->applyPeriod($this->base($companyId), $from, $to);
        $map->applyAccountFilter($q, $accountId);
        $bal = round($opening, 2);
        $running = [];
        foreach ($this->ordered($q, 'date_asc')->select(['l.uid', 'l.direction', 'l.amount'])->cursor() as $r) {
            $bal = round($bal + ($r->direction === 'in' ? 1 : -1) * (float) $r->amount, 2);
            $running[$r->uid] = $bal;
        }

        return [round($opening, 2), $bal, $running];
    }

    // ─── Manual entries ─────────────────────────────────────────────────────

    public function getEntry(int $id, int $companyId): FinanceEntryDTO
    {
        $e = $this->entries->findByIdAndCompany($id, $companyId);
        if (!$e) {
            throw new HttpException(404, 'Entry not found');
        }

        return $this->entryMapper->toDTO($e);
    }

    public function createEntry(int $companyId, array $d, ?int $userId): FinanceEntryDTO
    {
        $this->assertAccount($companyId, (int) $d['accountId']);
        $kind = $d['kind'];
        $e = $this->entries->create([
            'company_id' => $companyId,
            'account_id' => (int) $d['accountId'],
            'kind' => $kind,
            'direction' => $this->entryDirection($kind, $d['direction'] ?? null),
            'amount' => round((float) $d['amount'], 2),
            'date' => $d['date'],
            'category' => $d['category'] ?? null,
            'party' => $d['party'] ?? null,
            'reference' => $d['reference'] ?? null,
            'note' => $d['note'] ?? null,
            'created_by' => $userId,
            'created_by_name' => ActorName::current(),
        ]);

        return $this->entryMapper->toDTO($e);
    }

    public function updateEntry(int $id, int $companyId, array $d): FinanceEntryDTO
    {
        $e = $this->entries->findByIdAndCompany($id, $companyId);
        if (!$e) {
            throw new HttpException(404, 'Entry not found');
        }
        if ($e->kind === \App\Models\FinanceEntry::KIND_SUPPLIER_RECEIPT) {
            throw new HttpException(422, 'Supplier receipts cannot be edited. Delete it and record it again.');
        }
        $data = [];
        if (array_key_exists('accountId', $d)) {
            $this->assertAccount($companyId, (int) $d['accountId']);
            $data['account_id'] = (int) $d['accountId'];
        }
        $kind = $d['kind'] ?? $e->kind;
        if (array_key_exists('kind', $d) || array_key_exists('direction', $d)) {
            $data['kind'] = $kind;
            $dir = $d['direction'] ?? ($kind === 'adjustment' ? $e->direction : null);
            $data['direction'] = $this->entryDirection($kind, $dir);
        }
        foreach (['amount' => 'amount', 'date' => 'date', 'category' => 'category', 'party' => 'party', 'reference' => 'reference', 'note' => 'note'] as $in => $col) {
            if (array_key_exists($in, $d)) {
                $data[$col] = $in === 'amount' ? round((float) $d[$in], 2) : $d[$in];
            }
        }

        return $this->entryMapper->toDTO($this->entries->update($e, $data));
    }

    public function deleteEntry(int $id, int $companyId): void
    {
        $e = $this->entries->findByIdAndCompany($id, $companyId);
        if (!$e) {
            throw new HttpException(404, 'Entry not found');
        }
        $this->entries->delete($e);
    }

    private function entryDirection(string $kind, ?string $direction): string
    {
        if ($kind === 'income') {
            return 'in';
        }
        if ($kind === 'expense') {
            return 'out';
        }
        if (!in_array($direction, ['in', 'out'], true)) {
            throw new HttpException(422, 'Choose whether the adjustment adds (in) or removes (out) money.');
        }

        return $direction;
    }

    private function assertAccount(int $companyId, int $accountId): void
    {
        $this->accounts->ensureDefaults($companyId);
        if (!isset($this->accounts->map($companyId)->accounts[$accountId])) {
            throw new HttpException(422, 'Account not found');
        }
    }

    // ─── Transfers ──────────────────────────────────────────────────────────

    public function listTransfers(int $companyId, array $in): array
    {
        $period = FinanceContext::period($companyId, $in, 'all_time');
        $f = [
            'from' => $period->fromDate(),
            'to' => $period->toDate(),
            'accountId' => $in['accountId'] ?? null,
            'perPage' => $in['perPage'] ?? $in['per_page'] ?? 25,
            'page' => $in['page'] ?? 1,
        ];
        $p = $this->transfers->paginateByCompany($companyId, $f);

        return [
            'data' => [
                'rows' => array_map(fn ($t) => $this->transferMapper->toDTO($t)->toArray(), $p->items()),
                'totals' => $this->transfers->totals($companyId, $f),
                'period' => $period->toArray(),
                'context' => FinanceContext::context($companyId, $period),
            ],
            'meta' => ['total' => $p->total(), 'perPage' => $p->perPage(), 'currentPage' => $p->currentPage(), 'lastPage' => $p->lastPage()],
        ];
    }

    public function getTransfer(int $id, int $companyId): FinanceTransferDTO
    {
        $t = $this->transfers->findByIdAndCompany($id, $companyId);
        if (!$t) {
            throw new HttpException(404, 'Transfer not found');
        }

        return $this->transferMapper->toDTO($t);
    }

    public function createTransfer(int $companyId, array $d, ?int $userId): FinanceTransferDTO
    {
        $this->assertTransferAccounts($companyId, (int) $d['fromAccountId'], (int) $d['toAccountId']);
        $t = $this->transfers->create([
            'company_id' => $companyId,
            'from_account_id' => (int) $d['fromAccountId'],
            'to_account_id' => (int) $d['toAccountId'],
            'amount' => round((float) $d['amount'], 2),
            'date' => $d['date'],
            'reference' => $d['reference'] ?? null,
            'note' => $d['note'] ?? null,
            'created_by' => $userId,
            'created_by_name' => ActorName::current(),
        ]);

        return $this->transferMapper->toDTO($t);
    }

    public function updateTransfer(int $id, int $companyId, array $d): FinanceTransferDTO
    {
        $t = $this->transfers->findByIdAndCompany($id, $companyId);
        if (!$t) {
            throw new HttpException(404, 'Transfer not found');
        }
        $from = (int) ($d['fromAccountId'] ?? $t->from_account_id);
        $to = (int) ($d['toAccountId'] ?? $t->to_account_id);
        $this->assertTransferAccounts($companyId, $from, $to);
        $data = ['from_account_id' => $from, 'to_account_id' => $to];
        foreach (['amount', 'date', 'reference', 'note'] as $k) {
            if (array_key_exists($k, $d)) {
                $data[$k] = $k === 'amount' ? round((float) $d[$k], 2) : $d[$k];
            }
        }

        return $this->transferMapper->toDTO($this->transfers->update($t, $data));
    }

    public function deleteTransfer(int $id, int $companyId): void
    {
        $t = $this->transfers->findByIdAndCompany($id, $companyId);
        if (!$t) {
            throw new HttpException(404, 'Transfer not found');
        }
        $this->transfers->delete($t);
    }

    private function assertTransferAccounts(int $companyId, int $from, int $to): void
    {
        if ($from === $to) {
            throw new HttpException(422, 'Pick two different accounts.');
        }
        $map = $this->accounts->map($companyId);
        if (!isset($map->accounts[$from]) || !isset($map->accounts[$to])) {
            throw new HttpException(422, 'Account not found');
        }
    }
}
