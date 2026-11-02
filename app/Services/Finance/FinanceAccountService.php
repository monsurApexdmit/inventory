<?php

namespace App\Services\Finance;

use App\DTOs\Finance\FinanceAccountDTO;
use App\DTOs\Finance\FinanceAccountMapper;
use App\Models\FinanceAccount;
use App\Repositories\Contracts\IFinanceAccountRepository;
use App\Repositories\Contracts\IFinanceLedgerRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Money accounts: defaults, CRUD, method → account mapping and balances computed from the ledger. */
class FinanceAccountService
{
    public const DEFAULTS = [
        ['name' => 'Cash', 'type' => 'cash', 'method_keys' => ['cash'], 'is_default' => true],
        ['name' => 'Bank', 'type' => 'bank', 'method_keys' => ['bank', 'card', 'online'], 'is_default' => false],
        ['name' => 'bKash', 'type' => 'mobile_wallet', 'method_keys' => ['bkash'], 'is_default' => false],
        ['name' => 'Nagad', 'type' => 'mobile_wallet', 'method_keys' => ['nagad'], 'is_default' => false],
    ];

    private readonly FinanceAccountMapper $mapper;

    public function __construct(
        private readonly IFinanceAccountRepository $accounts,
        private readonly IFinanceLedgerRepository $ledger,
    ) {
        $this->mapper = new FinanceAccountMapper();
    }

    /** Create the default accounts once for a company that has none (safe under concurrent requests). */
    public function ensureDefaults(int $companyId): void
    {
        if ($this->accounts->countByCompany($companyId) > 0) {
            return;
        }
        DB::transaction(function () use ($companyId) {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            if ($this->accounts->countByCompany($companyId) > 0) {
                return;
            }
            foreach (self::DEFAULTS as $d) {
                $this->accounts->create($d + ['company_id' => $companyId, 'opening_balance' => 0, 'is_active' => true]);
            }
        });
    }

    public function map(int $companyId): FinanceAccountMap
    {
        $this->ensureDefaults($companyId);

        $map = new FinanceAccountMap($this->accounts->allByCompany($companyId));
        $map->companyId = $companyId;

        return $map;
    }

    public function find(int $id, int $companyId): FinanceAccount
    {
        $this->ensureDefaults($companyId);
        $a = $this->accounts->findByIdAndCompany($id, $companyId);
        if (!$a) {
            throw new HttpException(404, 'Account not found');
        }

        return $a;
    }

    /**
     * Ledger activity per account between dates (inclusive, null = open).
     *
     * @return array<int, array{in: float, out: float, count: int, last: ?string, transfersIn: float, transfersOut: float}>
     */
    public function activity(int $companyId, FinanceAccountMap $map, ?string $from = null, ?string $to = null): array
    {
        $rows = $this->ledger->query($companyId)
            ->when($from, fn ($q) => $q->where('l.txn_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('l.txn_date', '<=', $to))
            ->groupBy('l.account_id', 'l.mkey', 'l.direction', 'l.source_type')
            ->selectRaw('l.account_id, l.mkey, l.direction, l.source_type, SUM(l.amount) AS amt, COUNT(*) AS n, MAX(l.txn_date) AS last')
            ->get();

        $out = [];
        foreach ($map->accounts as $id => $_) {
            $out[$id] = ['in' => 0.0, 'out' => 0.0, 'count' => 0, 'last' => null, 'transfersIn' => 0.0, 'transfersOut' => 0.0];
        }
        foreach ($rows as $r) {
            $id = $map->resolve($r->account_id, $r->mkey);
            if ($id === null || !isset($out[$id])) {
                continue;
            }
            $amt = (float) $r->amt;
            $out[$id][$r->direction === 'in' ? 'in' : 'out'] += $amt;
            if ($r->source_type === 'transfer') {
                $out[$id][$r->direction === 'in' ? 'transfersIn' : 'transfersOut'] += $amt;
            }
            $out[$id]['count'] += (int) $r->n;
            $last = $r->last ? substr((string) $r->last, 0, 10) : null;
            if ($last && ($out[$id]['last'] === null || $last > $out[$id]['last'])) {
                $out[$id]['last'] = $last;
            }
        }
        foreach ($out as &$o) {
            $o['in'] = round($o['in'], 2);
            $o['out'] = round($o['out'], 2);
            $o['transfersIn'] = round($o['transfersIn'], 2);
            $o['transfersOut'] = round($o['transfersOut'], 2);
        }

        return $out;
    }

    /** Balance of every account at the end of $asOf (null = all time) keyed by id. */
    public function balances(int $companyId, FinanceAccountMap $map, ?string $asOf = null): array
    {
        $act = $this->activity($companyId, $map, null, $asOf);
        $out = [];
        foreach ($map->accounts as $id => $a) {
            $out[$id] = round((float) $a->opening_balance + $act[$id]['in'] - $act[$id]['out'], 2);
        }

        return $out;
    }

    public function toDto(FinanceAccount $a, array $activity): FinanceAccountDTO
    {
        return $this->mapper->toDTO($a, $activity);
    }

    public function list(int $companyId, array $f): array
    {
        $asOf = !empty($f['asOf']) ? (string) $f['asOf'] : now()->toDateString();
        $includeInactive = !isset($f['includeInactive']) || filter_var($f['includeInactive'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;

        $map = $this->map($companyId);
        $act = $this->activity($companyId, $map, null, $asOf);

        $rows = [];
        $tot = ['balance' => 0.0, 'moneyIn' => 0.0, 'moneyOut' => 0.0, 'activeCount' => 0];
        foreach ($map->accounts as $id => $a) {
            if (!$includeInactive && !$a->is_active) {
                continue;
            }
            $dto = $this->mapper->toDTO($a, $act[$id]);
            $rows[] = $dto->toArray();
            $tot['balance'] += $dto->balance;
            $tot['moneyIn'] += $dto->moneyIn;
            $tot['moneyOut'] += $dto->moneyOut;
            $tot['activeCount'] += $a->is_active ? 1 : 0;
        }

        // Method keys actually used in the ledger that no account maps explicitly (they post to the default account).
        $used = $this->ledger->query($companyId)->whereNotNull('l.mkey')
            ->when($asOf, fn ($q) => $q->where('l.txn_date', '<=', $asOf))
            ->groupBy('l.mkey')->selectRaw('l.mkey, SUM(l.amount) AS amt, COUNT(*) AS n')->get();
        $unmapped = [];
        foreach ($used as $u) {
            if (!isset($map->keyToAccount[$u->mkey])) {
                $unmapped[] = ['key' => $u->mkey, 'label' => FinanceMethods::label($u->mkey), 'amount' => round((float) $u->amt, 2), 'count' => (int) $u->n];
            }
        }

        $options = [];
        $seen = [];
        foreach (array_merge(array_keys(FinanceMethods::LABELS), array_keys($map->keyToAccount), array_column($unmapped, 'key')) as $k) {
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $options[] = ['key' => $k, 'label' => FinanceMethods::label($k), 'accountId' => $map->keyToAccount[$k] ?? null];
        }

        return [
            'accounts' => $rows,
            'totals' => [
                'balance' => round($tot['balance'], 2),
                'moneyIn' => round($tot['moneyIn'], 2),
                'moneyOut' => round($tot['moneyOut'], 2),
                'activeCount' => $tot['activeCount'],
            ],
            'asOf' => $asOf,
            'methodOptions' => $options,
            'unmappedMethods' => $unmapped,
            'defaultAccountId' => $map->defaultId,
        ];
    }

    public function get(int $id, int $companyId): FinanceAccountDTO
    {
        $a = $this->find($id, $companyId);
        $map = $this->map($companyId);
        $act = $this->activity($companyId, $map, null, now()->toDateString());

        return $this->mapper->toDTO($a, $act[$a->id] ?? []);
    }

    public function create(int $companyId, array $d): FinanceAccountDTO
    {
        $this->ensureDefaults($companyId);
        $name = trim((string) $d['name']);
        if ($this->accounts->nameExists($companyId, $name)) {
            throw new HttpException(422, "An account named \"{$name}\" already exists.");
        }
        $keys = $this->normalizeKeys($d['methodKeys'] ?? []);
        $this->assertKeysFree($companyId, $keys, null);
        $isDefault = (bool) ($d['isDefault'] ?? false);
        $isActive = array_key_exists('isActive', $d) ? (bool) $d['isActive'] : true;
        if ($isDefault && !$isActive) {
            throw new HttpException(422, 'The default account cannot be inactive.');
        }

        $account = DB::transaction(function () use ($companyId, $d, $name, $keys, $isDefault, $isActive) {
            if ($isDefault) {
                $this->accounts->clearDefault($companyId);
            }

            return $this->accounts->create([
                'company_id' => $companyId,
                'name' => $name,
                'type' => $d['type'],
                'method_keys' => $keys,
                'opening_balance' => round((float) ($d['openingBalance'] ?? 0), 2),
                'is_default' => $isDefault,
                'is_active' => $isActive,
                'note' => $d['note'] ?? null,
            ]);
        });

        return $this->get((int) $account->id, $companyId);
    }

    public function update(int $id, int $companyId, array $d): FinanceAccountDTO
    {
        $a = $this->find($id, $companyId);
        $data = [];
        if (array_key_exists('name', $d)) {
            $name = trim((string) $d['name']);
            if ($this->accounts->nameExists($companyId, $name, $a->id)) {
                throw new HttpException(422, "An account named \"{$name}\" already exists.");
            }
            $data['name'] = $name;
        }
        if (array_key_exists('type', $d)) {
            $data['type'] = $d['type'];
        }
        if (array_key_exists('methodKeys', $d)) {
            $keys = $this->normalizeKeys($d['methodKeys'] ?? []);
            $this->assertKeysFree($companyId, $keys, $a->id);
            $data['method_keys'] = $keys;
        }
        if (array_key_exists('openingBalance', $d)) {
            $data['opening_balance'] = round((float) ($d['openingBalance'] ?? 0), 2);
        }
        if (array_key_exists('note', $d)) {
            $data['note'] = $d['note'];
        }
        $willBeDefault = array_key_exists('isDefault', $d) ? (bool) $d['isDefault'] : (bool) $a->is_default;
        $willBeActive = array_key_exists('isActive', $d) ? (bool) $d['isActive'] : (bool) $a->is_active;
        if ($a->is_default && !$willBeDefault) {
            throw new HttpException(422, 'Set another account as default first.');
        }
        if ($willBeDefault && !$willBeActive) {
            throw new HttpException(422, 'The default account cannot be inactive.');
        }
        $data['is_active'] = $willBeActive;
        $data['is_default'] = $willBeDefault;

        DB::transaction(function () use ($a, $data, $companyId, $willBeDefault) {
            if ($willBeDefault) {
                $this->accounts->clearDefault($companyId, $a->id);
            }
            $this->accounts->update($a, $data);
        });

        return $this->get($a->id, $companyId);
    }

    public function setDefault(int $id, int $companyId): FinanceAccountDTO
    {
        $a = $this->find($id, $companyId);
        DB::transaction(function () use ($a, $companyId) {
            $this->accounts->clearDefault($companyId, $a->id);
            $this->accounts->update($a, ['is_default' => true, 'is_active' => true]);
        });

        return $this->get($a->id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $a = $this->find($id, $companyId);
        if ($a->is_default) {
            throw new HttpException(422, 'The default account cannot be deleted. Set another account as default first.');
        }
        if ($this->accounts->hasActivity($a->id)) {
            throw new HttpException(422, 'This account has manual entries or transfers. Delete them first or deactivate the account.');
        }
        $this->accounts->delete($a);
    }

    private function normalizeKeys(mixed $keys): array
    {
        $out = [];
        foreach ((array) $keys as $k) {
            $k = FinanceMethods::key(is_string($k) ? $k : (string) $k);
            if ($k !== '' && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }

        return $out;
    }

    private function assertKeysFree(int $companyId, array $keys, ?int $exceptId): void
    {
        if (!$keys) {
            return;
        }
        foreach ($this->accounts->allByCompany($companyId) as $other) {
            if ($exceptId !== null && (int) $other->id === $exceptId) {
                continue;
            }
            $taken = array_intersect($keys, (array) ($other->method_keys ?? []));
            if ($taken) {
                $k = reset($taken);
                throw new HttpException(422, 'Payment method "' . FinanceMethods::label($k) . "\" is already mapped to account \"{$other->name}\".");
            }
        }
    }
}
